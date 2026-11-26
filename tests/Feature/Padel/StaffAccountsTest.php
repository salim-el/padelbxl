<?php

namespace Tests\Feature\Padel;

use App\Models\BlockedIdentity;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** F35 : l'administrateur gère les comptes du personnel et leurs rôles. */
class StaffAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->admin = User::where('email', 'claire.vermeulen@example.test')->firstOrFail();
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
    }

    public function test_only_the_administrator_manages_staff(): void
    {
        $this->actingAs($this->marc)->get('/admin/staff')->assertForbidden();
        $this->actingAs($this->marc)->post('/admin/staff', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'admin'])->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/staff')->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/staff')->has('staff', 2));
    }

    public function test_a_new_member_gets_a_link_to_choose_a_password(): void
    {
        $this->actingAs($this->admin)->post('/admin/staff', ['name' => 'Nora Smets', 'email' => 'nora.smets@example.test', 'role' => 'manager'])
            ->assertSessionHasNoErrors();

        $nora = User::where('email', 'nora.smets@example.test')->firstOrFail();
        $this->assertSame('manager', $nora->role);
        $this->assertNotNull($nora->email_verified_at);
        Notification::assertSentTo($nora, StaffInvitation::class, fn (StaffInvitation $n) => str_contains($n->toMail($nora)->actionUrl, '/reset-password/'));
        $this->assertTrue(Activity::where('event', 'staff-invited')->where('causer_id', $this->admin->id)->exists());
    }

    public function test_a_player_is_promoted_and_loses_his_api_tokens(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $lucas->createToken('phone', ['bookings']);

        $this->actingAs($this->admin)->post('/admin/staff', ['name' => 'ignoré', 'email' => $lucas->email, 'role' => 'manager'])->assertSessionHasNoErrors();

        $lucas->refresh();
        $this->assertSame('manager', $lucas->role);
        $this->assertSame('Lucas Martin', $lucas->name);
        $this->assertSame(0, $lucas->tokens()->count());
        Notification::assertSentTo($lucas, StaffInvitation::class, fn (StaffInvitation $n) => str_ends_with($n->toMail($lucas)->actionUrl, '/admin'));
    }

    public function test_revoked_addresses_and_current_staff_are_refused(): void
    {
        BlockedIdentity::create(['email_hash' => BlockedIdentity::hashEmail('fraude@example.test'), 'reason' => 'Fraude à la carte']);

        $this->actingAs($this->admin)->post('/admin/staff', ['name' => 'F', 'email' => 'fraude@example.test', 'role' => 'manager'])->assertSessionHasErrors('staff');
        $this->actingAs($this->admin)->post('/admin/staff', ['name' => 'M', 'email' => $this->marc->email, 'role' => 'admin'])->assertSessionHasErrors('staff');
        $this->assertSame('manager', $this->marc->refresh()->role);
        $this->assertDatabaseMissing('users', ['email' => 'fraude@example.test']);
    }

    public function test_the_administrator_changes_a_role_but_not_his_own(): void
    {
        $this->actingAs($this->admin)->patch("/admin/staff/{$this->marc->id}", ['role' => 'player'])->assertSessionHasNoErrors();
        $this->assertSame('player', $this->marc->refresh()->role);
        $this->actingAs($this->marc)->get('/admin')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/journal')
            ->assertInertia(fn ($page) => $page->where('entries.data.0.event', 'staff-role-changed')->where('entries.data.0.details', 'Gérant → Joueur'));

        $this->actingAs($this->admin)->patch("/admin/staff/{$this->admin->id}", ['role' => 'manager'])->assertSessionHasErrors('staff');
        $this->assertSame('admin', $this->admin->refresh()->role);
        // Un joueur ne change pas de rôle par cette route : il passe par l'ajout, avec l'e-mail qui le prévient.
        $this->actingAs($this->admin)->patch("/admin/staff/{$this->marc->id}", ['role' => 'admin'])->assertSessionHasErrors('staff');
    }

    public function test_the_link_can_be_sent_again(): void
    {
        $this->actingAs($this->admin)->post("/admin/staff/{$this->marc->id}/resend")->assertSessionHasNoErrors();
        Notification::assertSentTo($this->marc, StaffInvitation::class);
    }
}
