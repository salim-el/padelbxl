<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\User;
use App\Services\AccessService;
use App\Services\AccountStatusService;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $lucas = User::where('email', 'lucas.martin@example.test')->first();
        $service = app(BookingService::class);
        $this->start = Clock::slotStart('2026-10-23', '19:30');
        $booking = $service->confirmPayment($service->hold($lucas, $this->court(2), $this->start), $lucas);
        $this->token = BookingService::passToken($booking->players()->first());
    }

    // Les séquences PostgreSQL ne reviennent pas à 1 entre deux tests : on cherche le terrain par son nom.
    private function court(int $n): Court
    {
        return Court::where('name', "Terrain $n")->firstOrFail();
    }

    public function test_a_partner_suspended_after_the_guarantee_paid_his_place_is_refused_at_the_kiosk(): void
    {
        Notification::fake();
        $lucas = User::where('email', 'lucas.martin@example.test')->first();
        $ines = User::where('email', 'ines.laurent@example.test')->first();
        $service = app(BookingService::class);
        $start = Clock::slotStart('2026-10-23', '21:00');
        $booking = $service->confirmPayment($service->hold($lucas, $this->court(2), $start), $lucas, [], 'card', [$ines->email]);
        // À moins de 24 h, la garantie de Lucas paie la part d'Inès : sa place passe en covered, avec un QR code.
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));
        $service->chargeUnpaidShares();
        $place = $booking->players()->where('user_id', $ines->id)->sole();
        $this->assertSame('covered', $place->status);

        app(AccountStatusService::class)->suspend($ines, User::where('email', 'marc.dumont@example.test')->first(), 'Comportement violent au club.', 7);

        $this->travelTo($start->subMinutes(10));
        $access = app(AccessService::class);
        $this->assertSame('revoked', $access->validate(BookingService::passToken($place), $this->court(2))['reason']);
        $this->assertSame('granted', $access->validate(BookingService::passToken($booking->players()->where('user_id', $lucas->id)->sole()), $this->court(2))['result']);
    }

    public function test_qr_code_opens_once_then_is_refused(): void
    {
        $this->travelTo($this->start->subMinutes(10));
        $access = app(AccessService::class);
        $this->assertSame('granted', $access->validate($this->token, $this->court(2))['result']);
        $this->assertSame('already_used', $access->validate($this->token, $this->court(2))['reason']);
        $this->assertSame(2, DB::table('access_logs')->count());
        $this->assertSame(1, DB::table('booking_status_histories')->where('to_status', 'used')->count());
    }

    public function test_wrong_court_too_early_and_unknown_are_refused(): void
    {
        $access = app(AccessService::class);
        $this->travelTo($this->start->subMinutes(10));
        $this->assertSame('wrong_court', $access->validate($this->token, $this->court(3))['reason']);
        $this->assertSame('unknown', $access->validate('faux-jeton', $this->court(2))['reason']);
        $this->travelTo($this->start->subMinutes(30));
        $this->assertSame('too_early', $access->validate($this->token, $this->court(2))['reason']);
    }

    public function test_only_staff_can_open_the_kiosk(): void
    {
        // Joueur actif désigné par son e-mail : sans ordre, PostgreSQL peut rendre le compte suspendu, qui est déconnecté au lieu de recevoir 403.
        $this->actingAs(User::where('email', 'lucas.martin@example.test')->first())->get('/kiosk')->assertForbidden();
        $this->actingAs(User::where('role', 'manager')->first())->get('/kiosk')->assertOk();
    }
}
