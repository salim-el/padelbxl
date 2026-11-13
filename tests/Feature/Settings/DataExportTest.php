<?php

namespace Tests\Feature\Settings;

use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private User $ines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        // Lucas partage une partie avec Inès, qui paie sa part.
        $service = app(BookingService::class);
        $hold = $service->hold($this->lucas, Court::where('name', 'Terrain 1')->firstOrFail(), Clock::slotStart('2026-10-23', '19:30'));
        $booking = $service->confirmPayment($hold, $this->lucas, [], 'card', [$this->ines->email]);
        $service->payShare($booking, $this->ines);
    }

    public function test_a_player_downloads_his_own_data_and_the_export_is_logged(): void
    {
        $response = $this->actingAs($this->lucas)->get('/settings/export')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="padelbxl-mes-donnees.json"');

        $response->assertJsonPath('profile.email', 'lucas.martin@example.test')
            ->assertJsonCount(1, 'bookings_as_organizer')
            ->assertJsonPath('bookings_as_organizer.0.partners', 1)
            ->assertJsonCount(1, 'payments')
            ->assertJsonCount(1, 'invoices');
        // Jamais les données d'un autre joueur : ni l'adresse invitée ni le nom de l'invitée.
        $this->assertStringNotContainsString('ines', strtolower($response->getContent()));
        $this->assertTrue(Activity::where('event', 'data-exported')->where('causer_id', $this->lucas->id)->exists());
    }

    public function test_a_partner_sees_his_share_but_not_the_organizer(): void
    {
        $response = $this->actingAs($this->ines)->get('/settings/export')->assertOk()
            ->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.share_status', 'paid')
            ->assertJsonCount(1, 'invoices');

        $this->assertStringNotContainsString('lucas', strtolower($response->getContent()));
        $this->assertStringNotContainsString('martin', strtolower($response->getContent()));
    }

    public function test_the_export_needs_an_account_and_is_rate_limited(): void
    {
        $this->get('/settings/export')->assertRedirect('/login');

        foreach (range(1, 3) as $i) {
            $this->actingAs($this->lucas)->get('/settings/export')->assertOk();
        }
        $this->actingAs($this->lucas)->get('/settings/export')->assertTooManyRequests();
    }
}
