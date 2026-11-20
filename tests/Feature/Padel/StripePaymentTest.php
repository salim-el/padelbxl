<?php

namespace Tests\Feature\Padel;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\Court;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\StoreCreditService;
use App\Services\StripePayments;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Stripe\Checkout\Session;
use Tests\TestCase;

/** F9 : Stripe en mode test. La passerelle est simulée : aucun appel ne part vers Stripe pendant les tests. */
class StripePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        // Partie de 19 h 30 le 23/10, aux heures pleines : 48 €.
        $this->booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->firstOrFail(), Clock::slotStart('2026-10-23', '19:30'));
    }

    private function stripeSession(int $amount, string $status = 'paid'): Session
    {
        return Session::constructFrom([
            'id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
            'payment_status' => $status, 'currency' => 'eur', 'metadata' => ['booking' => $this->booking->public_id],
            'payment_intent' => ['id' => 'pi_test_1', 'object' => 'payment_intent', 'payment_method' => 'pm_card_visa', 'amount_received' => $amount],
        ]);
    }

    /** @param callable(MockInterface): void $expect */
    private function stripe(callable $expect): void
    {
        $this->mock(StripePayments::class, function (MockInterface $mock) use ($expect) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $expect($mock);
        });
    }

    private function pay(array $extra = []): TestResponse
    {
        return $this->actingAs($this->lucas)->post("/bookings/{$this->booking->public_id}/pay", ['accept_terms' => '1', ...$extra]);
    }

    public function test_the_organizer_pays_on_the_stripe_page_and_the_return_confirms_once(): void
    {
        $this->stripe(function (MockInterface $mock) {
            $mock->shouldReceive('checkout')->once()->withArgs(fn ($b, $u, $amount, $label, $keep) => $amount === 4800 && $keep === false && str_starts_with($label, 'Terrain 1'))->andReturn($this->stripeSession(4800));
            $mock->shouldReceive('session')->with('cs_test_1')->andReturn($this->stripeSession(4800));
            $mock->shouldNotReceive('refund');
        });

        $this->pay()->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $this->assertSame('pending_payment', $this->booking->fresh()->status);

        $back = "/bookings/{$this->booking->public_id}/paid?session_id=cs_test_1";
        $this->actingAs($this->lucas)->get($back)->assertRedirect("/bookings/{$this->booking->public_id}")->assertSessionHas('success');
        $this->assertSame('confirmed', $this->booking->fresh()->status);
        $this->assertSame(['pi_test_1', 4800], [Payment::sole()->provider_reference, Payment::sole()->amount_cents]);
        // Un rafraîchissement de la page de retour ne paie ni ne confirme une seconde fois.
        $this->actingAs($this->lucas)->get($back)->assertRedirect("/bookings/{$this->booking->public_id}");
        $this->assertSame(1, Payment::count());
    }

    public function test_a_payment_arriving_after_the_ten_minutes_is_refunded_in_full(): void
    {
        $this->stripe(function (MockInterface $mock) {
            $mock->shouldReceive('checkout')->andReturn($this->stripeSession(4800));
            $mock->shouldReceive('session')->andReturn($this->stripeSession(4800));
            $mock->shouldReceive('refund')->once()->with('pi_test_1', 4800, 'late-pi_test_1')->andReturn('re_test_1');
        });

        $this->pay();
        $this->travel(11)->minutes();

        $this->actingAs($this->lucas)->get("/bookings/{$this->booking->public_id}/paid?session_id=cs_test_1")->assertSessionHasErrors('booking');
        $this->assertNotSame('confirmed', $this->booking->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_credit_spent_meanwhile_or_a_lost_order_confirms_nothing_and_refunds(): void
    {
        $this->stripe(function (MockInterface $mock) {
            // Avoir de 10 € au départ : Stripe encaisse 38 €. L'avoir est dépensé ailleurs avant le retour.
            $mock->shouldReceive('checkout')->once()->withArgs(fn ($b, $u, $amount) => $amount === 3800)->andReturn($this->stripeSession(3800));
            $mock->shouldReceive('session')->andReturn($this->stripeSession(3800));
            $mock->shouldReceive('refund')->twice()->with('pi_test_1', 3800, 'late-pi_test_1')->andReturn('re_test_1');
        });
        $credit = app(StoreCreditService::class)->grant($this->lucas, 1000, 'goodwill');

        $this->pay();
        $credit->update(['remaining_cents' => 0]);
        $back = "/bookings/{$this->booking->public_id}/paid?session_id=cs_test_1";
        $this->actingAs($this->lucas)->get($back)->assertSessionHasErrors('booking');
        $this->assertSame([0, 'pending_payment'], [Payment::count(), $this->booking->fresh()->status]);

        // Commande perdue (cache vidé) : rien n'est deviné, le paiement est rendu.
        Cache::flush();
        $this->actingAs($this->lucas)->get($back)->assertSessionHasErrors('booking');
        $this->assertSame(0, Payment::count());
    }

    public function test_an_unpaid_session_or_another_booking_confirms_nothing(): void
    {
        $this->stripe(function (MockInterface $mock) {
            $mock->shouldReceive('session')->andReturn($this->stripeSession(0, 'unpaid'));
        });

        $this->actingAs($this->lucas)->get("/bookings/{$this->booking->public_id}/paid?session_id=cs_test_1")->assertRedirect("/bookings/{$this->booking->public_id}/checkout");
        $this->actingAs($this->lucas)->get("/bookings/{$this->booking->public_id}/paid?session_id=faux")->assertNotFound();
        $this->actingAs(User::where('email', 'ines.laurent@example.test')->firstOrFail())->get("/bookings/{$this->booking->public_id}/paid?session_id=cs_test_1")->assertNotFound();
        $this->assertSame(0, Payment::count());
    }

    public function test_a_shared_price_keeps_the_card_and_the_guarantee_pays_the_unpaid_share_on_stripe(): void
    {
        $this->stripe(function (MockInterface $mock) {
            // Lucas et Inès : 24 € chacun. Lucas paie sa part, sa carte est gardée.
            $mock->shouldReceive('checkout')->once()->withArgs(fn ($b, $u, $amount, $label, $keep) => $amount === 2400 && $keep === true)->andReturn($this->stripeSession(2400));
            $mock->shouldReceive('session')->andReturn($this->stripeSession(2400));
            $mock->shouldReceive('chargeGuarantee')->once()->withArgs(fn ($organizer, $method, $amount) => $organizer->is($this->lucas) && $method === 'pm_card_visa' && $amount === 2400)->andReturn('pi_guarantee_1');
        });

        $this->pay(['guests' => ['ines.laurent@example.test'], 'accept_guarantee' => '1']);
        $this->actingAs($this->lucas)->get("/bookings/{$this->booking->public_id}/paid?session_id=cs_test_1");
        $booking = $this->booking->fresh();
        $this->assertSame(['confirmed', 'pm_card_visa'], [$booking->status, $booking->guarantee_payment_method_id]);
        $this->assertNotNull($booking->guarantee_accepted_at);

        // À moins de 24 h, Inès n'a pas payé : la carte de garantie paie sa part chez Stripe.
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));
        $this->assertSame(1, app(BookingService::class)->chargeUnpaidShares());
        $guarantee = Payment::where('provider_reference', 'pi_guarantee_1')->sole();
        $this->assertTrue($guarantee->is_guarantee_charge);
        $this->assertSame('covered', BookingPlayer::find($guarantee->booking_player_id)->status);
    }

    public function test_a_declined_guarantee_card_leaves_the_share_unpaid_without_stopping_the_others(): void
    {
        $this->stripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeGuarantee')->andThrow(new BookingException('La carte de garantie a été refusée.'));
        });
        $service = app(BookingService::class);
        $booking = $service->confirmPayment($this->booking, $this->lucas, [], 'card', ['ines.laurent@example.test'], 0, 'pi_test_2', 'pm_card_chargeDeclined');

        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));

        $this->assertSame(0, $service->chargeUnpaidShares());
        $this->assertSame('invited', $booking->players()->where('is_organizer', false)->sole()->status);
    }

    public function test_a_stripe_payment_is_refunded_on_stripe_with_its_reference(): void
    {
        $this->stripe(function (MockInterface $mock) {
            $mock->shouldReceive('refund')->once()->withArgs(fn ($intent, $amount) => $intent === 'pi_test_3' && $amount === 4800)->andReturn('re_test_3');
        });
        $service = app(BookingService::class);
        $booking = $service->confirmPayment($this->booking, $this->lucas, [], 'card', [], 0, 'pi_test_3');

        $service->cancel($booking, $this->lucas);

        $this->assertSame(['re_test_3', 4800], [Refund::sole()->stripe_refund_id, Refund::sole()->amount_cents]);
    }
}
