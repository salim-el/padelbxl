<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\Court;
use App\Models\User;
use App\Notifications\AccessPassLink;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** F18 et F25 : lien personnel vers le QR code pour un joueur sans compte. */
class PassLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $marc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->marc = User::where('email', 'marc.dumont@example.test')->first();
    }

    private function counter(string $contact): Booking
    {
        $this->actingAs($this->marc)->post('/admin/counter', [
            'court_id' => Court::first()->id, 'date' => '2026-10-23', 'time' => '18:00',
            'name' => 'Sofia Janssens', 'contact' => $contact, 'method' => 'cash',
        ])->assertRedirect();

        return Booking::whereNotNull('walk_in_name')->latest('id')->firstOrFail();
    }

    private function linkSentTo(string $email): string
    {
        $link = null;
        Notification::assertSentTo(new AnonymousNotifiable, AccessPassLink::class, function ($n, $channels, $notifiable) use (&$link, $email) {
            if (($notifiable->routes['mail'] ?? null) !== $email) {
                return false;
            }
            $link = $n->toMail($notifiable)->actionUrl;

            return true;
        });

        return $link;
    }

    public function test_a_counter_client_with_an_email_receives_a_link_that_shows_the_qr_code(): void
    {
        $this->counter('sofia.janssens@example.test');
        $link = $this->linkSentTo('sofia.janssens@example.test');
        auth()->logout();

        // Sans connexion : le lien signé suffit. Le QR n'est jamais dans l'e-mail lui-même.
        $this->get($link)->assertInertia(fn ($p) => $p->component('pass')->where('time', '18:00')->whereNot('qr', null));
    }

    public function test_a_counter_client_with_a_phone_number_keeps_the_printed_ticket_only(): void
    {
        $this->counter('0470 12 34 56');

        Notification::assertNothingSent();
    }

    public function test_a_tampered_or_expired_link_is_refused_and_a_cancelled_booking_shows_no_qr(): void
    {
        $booking = $this->counter('sofia.janssens@example.test');
        $link = $this->linkSentTo('sofia.janssens@example.test');
        auth()->logout();

        // Signature modifiée : refusé.
        $this->get(preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $link))->assertForbidden();
        $this->actingAs($this->marc)->post("/admin/counter/{$booking->public_id}/cancel");
        auth()->logout();
        $this->get($link)->assertInertia(fn ($p) => $p->where('qr', null));

        $this->travelTo(CarbonImmutable::parse('2026-10-23 19:31', Clock::ZONE));
        $this->get($link)->assertForbidden();
    }

    public function test_a_guest_without_account_whose_share_is_covered_receives_the_link(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->first();
        $service = app(BookingService::class);
        $booking = $service->confirmPayment($service->hold($lucas, Court::first(), Clock::slotStart('2026-10-23', '19:30')), $lucas, [], 'card', ['nouveau.joueur@example.test']);

        $this->travelTo(CarbonImmutable::parse('2026-10-22 19:35', Clock::ZONE));
        $this->artisan('bookings:charge-unpaid-shares')->assertSuccessful();

        $this->assertSame('covered', BookingPlayer::where('booking_id', $booking->id)->where('guest_email', 'nouveau.joueur@example.test')->value('status'));
        $link = $this->linkSentTo('nouveau.joueur@example.test');
        $this->get($link)->assertInertia(fn ($p) => $p->component('pass')->whereNot('qr', null));
    }
}
