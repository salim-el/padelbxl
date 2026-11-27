<?php

namespace Tests\Feature\Auth;

use App\Models\Consent;
use App\Models\Court;
use App\Models\LegalDocument;
use App\Models\User;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalConsentTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $data = []): array
    {
        return $data + [
            'name' => 'Emma Lejeune',
            'email' => 'emma.lejeune@example.test',
            'account_type' => 'individual',
            'birth_date' => '1998-03-02',
            'level' => 4,
            'accept_terms' => true,
            'password' => 'mot-de-passe-long-2026',
            'password_confirmation' => 'mot-de-passe-long-2026',
        ];
    }

    public function test_registration_records_birth_date_level_and_the_accepted_versions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post('/register', $this->form())->assertSessionHasNoErrors();

        $user = User::where('email', 'emma.lejeune@example.test')->firstOrFail();
        $this->assertSame('1998-03-02', $user->birth_date->toDateString());
        $this->assertEquals(4, $user->level);
        // Les conditions en vigueur sont la version 2026.2, publiée avec le foot à 5 ; la vie privée reste en 2026.1.
        $this->assertSame(['privacy:2026.1', 'terms:2026.2'], Consent::where('user_id', $user->id)->with('document')->get()
            ->map(fn ($c) => "{$c->document->type}:{$c->document->version}")->sort()->values()->all());
    }

    public function test_terms_must_be_accepted_and_disposable_addresses_are_refused(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post('/register', $this->form(['accept_terms' => false]))->assertSessionHasErrors('accept_terms');
        $this->post('/register', $this->form(['email' => 'emma@yopmail.com']))->assertSessionHasErrors('email');
        $this->post('/register', $this->form(['email' => 'emma@eu.mailinator.com']))->assertSessionHasErrors('email');
        $this->post('/register', $this->form(['level' => 9]))->assertSessionHasErrors('level');
        $this->assertGuest();
    }

    public function test_under_13_the_parent_email_is_required_and_kept(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', Clock::ZONE));

        $this->post('/register', $this->form(['birth_date' => '2015-01-10']))->assertSessionHasErrors('guardian_email');
        $this->post('/register', $this->form(['birth_date' => '2015-01-10', 'guardian_email' => 'emma.lejeune@example.test']))->assertSessionHasErrors('guardian_email');
        $this->post('/register', $this->form(['birth_date' => '2015-01-10', 'guardian_email' => 'parent.lejeune@example.test']))->assertSessionHasNoErrors();

        $this->assertSame('parent.lejeune@example.test', User::where('email', 'emma.lejeune@example.test')->value('guardian_email'));
    }

    public function test_a_new_version_must_be_accepted_before_booking_and_the_acceptance_is_dated(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Inscrit le 14 novembre, ce joueur n'a accepté que les conditions 2026.1 : la version 2026.2 (foot à 5) date du 26.
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', Clock::ZONE));
        $player = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', Clock::ZONE));
        $slot = ['court_id' => Court::first()->id, 'starts_at' => Clock::slotStart('2026-12-04', '19:30')->toIso8601String()];

        $this->actingAs($player)->get('/bookings')->assertRedirect('/legal/accept');
        $this->actingAs($player)->post('/bookings', $slot)->assertRedirect('/legal/accept');
        $this->actingAs($player)->get('/legal/accept')->assertInertia(fn ($page) => $page->component('legal/accept')->has('documents', 1)
            ->where('documents.0.type', 'terms')->where('documents.0.version', '2026.2')->where('documents.0.published_at', '2026-11-26'));

        $this->actingAs($player)->post('/legal/accept', ['accept' => true])->assertRedirect();
        $this->actingAs($player)->get('/bookings')->assertOk();
        $consent = Consent::where('user_id', $player->id)->whereHas('document', fn ($q) => $q->where('version', '2026.2'))->first();
        $this->assertNotNull($consent);
        $this->assertSame('2026-11-28 10:00', Clock::local($consent->accepted_at->toImmutable())->format('Y-m-d H:i'));
    }

    public function test_the_migration_publishes_the_2026_2_terms_on_a_database_already_in_service(): void
    {
        $migration = require database_path('migrations/2026_11_26_000003_publish_terms_version_2026_2.php');
        // Base neuve, sans document : DatabaseSeeder publiera la version, la migration n'ajoute rien.
        $migration->up();
        $this->assertSame(0, LegalDocument::count());

        // Base en ligne semée avant le foot : elle n'a que la version 2026.1.
        LegalDocument::create(['type' => 'terms', 'version' => '2026.1', 'locale' => 'fr', 'content' => 'Conditions générales, version 2026.1.', 'published_at' => '2026-01-01 00:00:00']);
        // Mise en ligne le 1er décembre : un joueur inscrit avant a accepté la version 2026.1, alors en vigueur.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 14:30', Clock::ZONE));
        $migration->up();
        $migration->up();
        $published = LegalDocument::where('version', '2026.2')->orderBy('locale')->get();
        $this->assertSame(['en', 'fr', 'nl'], $published->pluck('locale')->all());
        $this->assertTrue($published->every(fn (LegalDocument $d) => $d->published_at->equalTo(now())));

        // Une base neuve semée par DatabaseSeeder reçoit la même version, à la date du texte.
        $migration->down();
        $this->assertFalse(LegalDocument::where('version', '2026.2')->exists());
        $this->seed(DatabaseSeeder::class);
        $this->assertSame('2026-11-26', LegalDocument::where('version', '2026.2')->firstOrFail()->published_at->toDateString());
    }

    public function test_staff_are_not_stopped_and_the_api_answers_403(): void
    {
        $this->seed(DatabaseSeeder::class);
        LegalDocument::create(['type' => 'privacy', 'version' => '2026.2', 'locale' => 'fr', 'content' => 'Nouveau sous-traitant.', 'published_at' => now()->subDay()]);
        $manager = User::where('email', 'marc.dumont@example.test')->first();
        $player = User::where('email', 'lucas.martin@example.test')->first();

        $token = $player->createToken('Application mobile', ['bookings'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/bookings', ['court_id' => Court::first()->id, 'starts_at' => now()->addDay()->toIso8601String()])
            ->assertForbidden();
        // Le personnel n'est pas renvoyé vers la page d'acceptation.
        $this->assertNotSame(url('/legal/accept'), $this->actingAs($manager)->get('/admin')->headers->get('Location'));
    }
}
