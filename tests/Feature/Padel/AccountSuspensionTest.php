<?php

namespace Tests\Feature\Padel;

use App\Models\AccountStatusHistory;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Notifications\AccountRevoked;
use App\Notifications\AccountSuspended;
use App\Notifications\SuspensionAppealDecided;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** F28 et F29 : suspension motivée, levée automatique, révocation et contestation. */
class AccountSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Trois absences sans prévenir ce mois-ci.';

    private User $lucas;

    private User $marc;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
        $this->marc = User::where('email', 'marc.dumont@example.test')->first();
        // Un second membre du personnel pour examiner les contestations.
        $this->admin = User::factory()->create(['name' => 'Sofia Admin']);
        $this->admin->forceFill(['role' => 'admin'])->save();
    }

    private function suspend(?int $days = 7): AccountStatusHistory
    {
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/suspend", ['reason' => self::REASON, 'days' => $days])->assertRedirect();

        return AccountStatusHistory::where('user_id', $this->lucas->id)->latest('id')->firstOrFail();
    }

    private function appealLink(AccountStatusHistory $suspension): string
    {
        $link = null;
        Notification::assertSentTo($this->lucas, AccountSuspended::class, function ($n) use (&$link, $suspension) {
            $link = $n->toMail($this->lucas)->actionUrl;

            return str_contains($link, "/account/appeal/{$suspension->id}");
        });

        return $link;
    }

    public function test_a_suspension_needs_a_reason_and_is_sent_to_the_player_with_its_end(): void
    {
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/suspend", ['reason' => ''])->assertSessionHasErrors('reason');

        $suspension = $this->suspend(7);

        $this->lucas->refresh();
        $this->assertSame('suspended', $this->lucas->status);
        $this->assertSame('2026-11-22 10:00', Clock::local($this->lucas->suspended_until->toImmutable())->format('Y-m-d H:i'));
        $this->assertSame(self::REASON, $suspension->reason);
        $this->assertSame($this->marc->id, $suspension->changed_by);
        $this->assertSame('active', $suspension->from_status);
        Notification::assertSentTo($this->lucas, AccountSuspended::class);
    }

    public function test_a_fixed_suspension_is_lifted_automatically_at_its_end_only(): void
    {
        $this->suspend(7);

        $this->travelTo(CarbonImmutable::parse('2026-11-22 09:59', Clock::ZONE));
        $this->artisan('accounts:lift-suspensions')->assertSuccessful();
        $this->assertSame('suspended', $this->lucas->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00', Clock::ZONE));
        $this->artisan('accounts:lift-suspensions')->assertSuccessful();
        $this->assertSame('active', $this->lucas->fresh()->status);
        $this->assertNull($this->lucas->fresh()->suspended_until);
        $this->assertDatabaseHas('account_status_histories', ['user_id' => $this->lucas->id, 'to_status' => 'active', 'changed_by' => null]);
    }

    public function test_a_suspension_until_further_notice_is_never_lifted_by_the_task(): void
    {
        $this->suspend(null);

        $this->travelTo(CarbonImmutable::parse('2027-11-15 10:00', Clock::ZONE));
        $this->artisan('accounts:lift-suspensions')->assertSuccessful();
        $this->assertSame('suspended', $this->lucas->fresh()->status);
    }

    public function test_the_player_appeals_through_the_signed_link_and_another_staff_member_decides(): void
    {
        $suspension = $this->suspend(30);
        $link = $this->appealLink($suspension);

        $this->get($link)->assertOk();
        $this->post($link, ['message' => 'Je n’ai pas reçu les rappels : mon adresse avait changé.'])->assertRedirect('/login');
        $this->post($link, ['message' => 'Deuxième envoi de la même contestation.'])->assertSessionHasErrors('message');
        $appeal = SuspensionAppeal::firstOrFail();

        // Celui qui a suspendu ne décide pas.
        $this->actingAs($this->marc)->post("/admin/appeals/{$appeal->id}/decision", ['accepted' => true, 'decision_note' => 'Adresse corrigée, on lève.'])
            ->assertSessionHasErrors('appeal');
        $this->assertSame('suspended', $this->lucas->fresh()->status);

        $this->actingAs($this->admin)->post("/admin/appeals/{$appeal->id}/decision", ['accepted' => true, 'decision_note' => 'Adresse corrigée, on lève.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('accepted', $appeal->fresh()->status);
        $this->assertSame('active', $this->lucas->fresh()->status);
        Notification::assertSentTo($this->lucas, SuspensionAppealDecided::class);
    }

    public function test_a_forged_link_is_refused_and_an_old_suspension_cannot_be_appealed(): void
    {
        $suspension = $this->suspend(7);
        $this->post("/account/appeal/{$suspension->id}", ['message' => 'Lien fabriqué sans signature valide.'])->assertForbidden();

        $link = $this->appealLink($suspension);
        // Le gérant réactive le compte : l'ancien lien ne vaut plus.
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/reactivate");
        auth()->logout();
        $this->post($link, ['message' => 'Réponse à une suspension déjà terminée.'])->assertSessionHasErrors('message');
        $this->assertSame(0, SuspensionAppeal::count());
    }

    public function test_revocation_is_final_and_blocks_a_new_account_with_the_same_email(): void
    {
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/revoke", ['reason' => 'Fraude au paiement confirmée par la banque.'])->assertRedirect();

        $this->assertSame('revoked', $this->lucas->fresh()->status);
        Notification::assertSentTo($this->lucas, AccountRevoked::class);
        auth()->logout();
        $this->post('/login', ['email' => 'lucas.martin@example.test', 'password' => DatabaseSeeder::DEMO_PASSWORD]);
        $this->get('/bookings')->assertRedirect('/login');

        // L'adresse libérée reste refusée : l'empreinte la reconnaît, même en majuscules.
        $this->lucas->forceFill(['email' => 'archive-'.$this->lucas->id.'@padelbxl.invalid'])->save();
        $this->post('/register', [
            'name' => 'Lucas Martin', 'email' => 'Lucas.Martin@example.test', 'account_type' => 'individual',
            'birth_date' => '1995-04-12', 'level' => 3, 'accept_terms' => true,
            'password' => 'mot-de-passe-long-2026', 'password_confirmation' => 'mot-de-passe-long-2026',
        ])->assertSessionHasErrors('email');
    }

    public function test_players_cannot_suspend_revoke_or_read_appeals(): void
    {
        $ines = User::where('email', 'ines.laurent@example.test')->first();

        $this->actingAs($this->lucas)->post("/admin/users/{$ines->id}/suspend", ['reason' => self::REASON])->assertForbidden();
        $this->actingAs($this->lucas)->post("/admin/users/{$ines->id}/revoke", ['reason' => self::REASON])->assertForbidden();
        $this->actingAs($this->lucas)->get('/admin/appeals')->assertForbidden();
        $this->assertSame('active', $ines->fresh()->status);
    }
}
