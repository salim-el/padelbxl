<?php

namespace Tests\Feature\Padel;

use App\Models\AccessPass;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\MatchCancelled;
use App\Services\AccountStatusService;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Défauts relevés par la relecture du 14 novembre 2026 sur les remboursements et les comptes. */
class RefundGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private User $ines;

    private User $marc;

    private BookingService $service;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        config(['invoicing.seller' => ['name' => 'Club de test SRL', 'vat' => 'BE0999999999', 'street' => 'Rue du Test 1', 'postcode' => '1070', 'city' => 'Anderlecht']]);
        // Mardi 20/10 à 10 h. La partie de Lucas est le vendredi 23/10 à 19 h 30, aux heures pleines : 48 €.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
        $this->service = app(BookingService::class);
        $this->booking = $this->service->confirmPayment($this->hold('19:30'), $this->lucas);
    }

    private function hold(string $time): Booking
    {
        return $this->service->hold($this->lucas, Court::where('name', 'Terrain 1')->firstOrFail(), Clock::slotStart('2026-10-23', $time));
    }

    private function payment(): Payment
    {
        return Payment::where('booking_id', $this->booking->id)->where('payer_id', $this->lucas->id)->sole();
    }

    /** Seconde partie de Lucas, à 21 h, partagée avec Inès, qui a payé sa part de 24 €. */
    private function sharedWithInes(): Booking
    {
        $booking = $this->service->confirmPayment($this->hold('21:00'), $this->lucas, [], 'card', [$this->ines->email]);
        $this->service->payShare($booking, $this->ines);

        return $booking->fresh();
    }

    private function openPasses(Booking $booking): int
    {
        return AccessPass::whereIn('booking_player_id', $booking->players()->pluck('id'))->whereNull('revoked_at')->count();
    }

    public function test_a_cancellation_refunds_what_is_left_after_a_partial_refund(): void
    {
        $payment = $this->payment();
        // Le gérant a déjà rendu 10 € sur une demande exceptionnelle.
        DB::transaction(fn () => $this->service->refund($payment, 1000, 'Demande exceptionnelle acceptée.', 'dispute_accepted'));
        $this->assertSame('partially_refunded', $payment->fresh()->status);

        $booking = $this->service->cancel($this->booking, $this->lucas);

        $this->assertSame('cancelled_refunded', $booking->status);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(4800, (int) Refund::where('payment_id', $payment->id)->sum('amount_cents'));
        $this->assertSame([1000, 3800], CreditNote::orderBy('id')->pluck('total_cents')->all());
    }

    public function test_several_credit_notes_add_up_to_the_invoice_to_the_cent(): void
    {
        $payment = $this->payment();
        $invoice = Invoice::where('payment_id', $payment->id)->sole();
        // Trois remboursements de 16 € : la part hors TVA de chacun s'arrondit, le dernier prend le reste.
        foreach ([1600, 1600, 1600] as $amount) {
            DB::transaction(fn () => $this->service->refund($payment, $amount, 'Geste du club.', 'dispute_accepted'));
        }

        $notes = $invoice->creditNotes;
        $this->assertCount(3, $notes);
        $this->assertSame($invoice->net_cents, $notes->sum('net_cents'));
        $this->assertSame($invoice->vat_cents, $notes->sum('vat_cents'));
        $this->assertSame($invoice->total_cents, $notes->sum('total_cents'));

        // Chaque note se télécharge et la page de la réservation les liste toutes.
        $page = "/bookings/{$this->booking->public_id}";
        foreach ($notes as $note) {
            $this->actingAs($this->lucas)->get("{$page}/invoices/{$invoice->number}/credit-notes/{$note->number}/pdf")
                ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $this->actingAs($this->lucas)->get($page)->assertInertia(fn (Assert $p) => $p->where('invoices.0.credit_notes', $notes->pluck('number')->all()));
    }

    public function test_deactivating_an_account_deletes_its_api_tokens(): void
    {
        $this->lucas->createToken('Application mobile', ['bookings']);
        // Après le match : plus de réservation à venir, la désactivation passe.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00', Clock::ZONE));

        $this->actingAs($this->lucas)->delete('/settings/profile', ['password' => DatabaseSeeder::DEMO_PASSWORD])->assertRedirect('/');

        $this->assertSame('deactivated', User::withTrashed()->findOrFail($this->lucas->id)->status);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $this->lucas->id)->count());
    }

    public function test_a_late_cancellation_refunds_the_partner_and_charges_the_organizer(): void
    {
        $booking = $this->sharedWithInes();
        // Le jour du match, à moins de 24 h : l'annulation n'est plus remboursée à l'organisateur.
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));

        $booking = $this->service->cancel($booking, $this->lucas);

        $this->assertSame('cancelled_unrefunded', $booking->status);
        // Inès n'a rien décidé : ses 24 € lui reviennent, avec une note de crédit.
        $hers = Payment::where('booking_id', $booking->id)->where('payer_id', $this->ines->id)->sole();
        $this->assertSame('refunded', $hers->status);
        $this->assertSame('organizer_cancellation', Refund::where('payment_id', $hers->id)->value('reason'));
        // Lucas supporte tout le terrain : sa part, plus celle d'Inès sur sa carte de garantie.
        $this->assertSame(4800, (int) Payment::where('booking_id', $booking->id)->where('payer_id', $this->lucas->id)->where('status', 'succeeded')->sum('amount_cents'));
        $this->assertSame('covered', BookingPlayer::where('booking_id', $booking->id)->where('user_id', $this->ines->id)->value('status'));
        $this->assertSame(0, $this->openPasses($booking));
        Notification::assertSentTo($this->ines, MatchCancelled::class);
        Notification::assertNotSentTo($this->lucas, MatchCancelled::class);
    }

    public function test_the_manager_cancels_a_booking_with_a_full_refund_even_on_the_day(): void
    {
        // Le jour du match : un joueur ne serait plus remboursé, le club rembourse tout.
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));
        $url = "/admin/bookings/{$this->booking->public_id}/cancel";
        $this->actingAs($this->marc)->get('/admin?day=2026-10-23')
            ->assertInertia(fn (Assert $p) => $p->where('day', '2026-10-23')->where('today.0.cancellable', true));

        $this->actingAs($this->lucas)->post($url, ['reason' => 'Fuite d’eau sur le terrain 1.'])->assertForbidden();
        $this->actingAs($this->marc)->post($url, ['reason' => 'Fuite'])->assertSessionHasErrors('reason');
        $this->actingAs($this->marc)->post($url, ['reason' => 'Fuite d’eau sur le terrain 1.'])->assertSessionHasNoErrors();

        $booking = $this->booking->fresh();
        $this->assertSame('cancelled_refunded', $booking->status);
        $this->assertSame('refunded', $this->payment()->status);
        $this->assertSame('club_cancellation', Refund::where('payment_id', $this->payment()->id)->value('reason'));
        $this->assertSame(0, $this->openPasses($booking));
        $this->assertDatabaseHas('booking_status_histories', ['booking_id' => $booking->id, 'to_status' => 'cancelled_refunded', 'changed_by' => $this->marc->id]);
        Notification::assertSentTo($this->lucas, MatchCancelled::class);
        $this->actingAs($this->marc)->get('/admin?day=2026-10-23')->assertInertia(fn (Assert $p) => $p->where('today.0.cancellable', false));
    }

    public function test_a_request_accepted_in_full_before_the_match_cancels_the_booking(): void
    {
        $disputes = app(DisputeService::class);
        $dispute = $disputes->open($this->lucas, $this->booking, 'Hospitalisation la veille du match, certificat joint.', null);

        $disputes->decide($dispute, $this->marc, 'accepted_full', 'Certificat reçu, remboursement complet.');

        // Remboursé, Lucas ne joue plus : la réservation est annulée et son QR code ne passe plus.
        $booking = $this->booking->fresh();
        $this->assertSame('cancelled_refunded', $booking->status);
        $this->assertSame('refunded', $this->payment()->status);
        $this->assertSame(0, $this->openPasses($booking));
    }

    public function test_a_partner_refunded_in_full_leaves_the_match_which_goes_on(): void
    {
        $booking = $this->sharedWithInes();
        $disputes = app(DisputeService::class);

        $disputes->decide($disputes->open($this->ines, $booking, 'Blessure à l’entraînement, certificat joint.', null), $this->marc, 'accepted_full', 'Certificat reçu.');

        $place = BookingPlayer::where('booking_id', $booking->id)->where('user_id', $this->ines->id)->sole();
        $this->assertSame('withdrawn', $place->status);
        $this->assertNotNull($place->accessPass->revoked_at);
        $this->assertSame('confirmed', $booking->fresh()->status);
        // La garantie ne débite pas Lucas pour une place que le club a libérée.
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));
        $this->assertSame(0, $this->service->chargeUnpaidShares());
    }

    public function test_suspending_an_account_cancels_and_refunds_its_upcoming_bookings(): void
    {
        $this->sharedWithInes();

        app(AccountStatusService::class)->suspend($this->lucas, $this->marc, 'Comportement dangereux signalé par deux joueurs.', 7);

        // Ses deux parties à venir tombent, chaque payeur est remboursé, plus aucun QR code ne passe.
        $this->assertSame(['cancelled_refunded', 'cancelled_refunded'], Booking::where('organizer_id', $this->lucas->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, Payment::whereIn('status', ['succeeded', 'partially_refunded'])->count());
        $this->assertSame(0, AccessPass::whereNull('revoked_at')->count());
        $this->assertSame('suspension', Refund::where('payment_id', $this->payment()->id)->value('reason'));
        // Inès est prévenue, sans apprendre que le compte de Lucas est suspendu.
        Notification::assertSentTo($this->ines, MatchCancelled::class, function (MatchCancelled $mail) {
            $text = implode(' ', $mail->toMail($this->ines)->introLines);

            return str_contains($text, 'annulée par le club') && ! str_contains($text, 'suspendu');
        });
    }

    public function test_a_suspended_partner_gets_his_share_back_and_leaves_the_match(): void
    {
        $booking = $this->sharedWithInes();

        app(AccountStatusService::class)->suspend($this->ines, $this->marc, 'Insultes répétées envers le personnel.', null);

        $place = BookingPlayer::where('booking_id', $booking->id)->where('user_id', $this->ines->id)->sole();
        $this->assertSame('withdrawn', $place->status);
        $this->assertNotNull($place->accessPass->revoked_at);
        $this->assertSame('refunded', Payment::where('booking_id', $booking->id)->where('payer_id', $this->ines->id)->sole()->status);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }
}
