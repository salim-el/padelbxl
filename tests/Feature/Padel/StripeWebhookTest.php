<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Chargeback;
use App\Models\Court;
use App\Models\FraudFlag;
use App\Models\Payment;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
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

/** F9 et F30 : notifications signées de Stripe. La signature est calculée ici avec un secret factice ; rien ne part vers Stripe. */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_padelbxl';

    private User $lucas;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        config(['services.stripe.webhook_secret' => self::SECRET]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->booking = app(BookingService::class)->hold($this->lucas, Court::where('name', 'Terrain 1')->firstOrFail(), Clock::slotStart('2026-10-23', '19:30'));
        // Seules enabled() et session() sont simulées : la vérification de signature reste celle du SDK Stripe.
        $this->partialMock(StripePayments::class, function (MockInterface $mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('session')->andReturn(Session::constructFrom([
                'id' => 'cs_test_9', 'object' => 'checkout.session', 'payment_status' => 'paid', 'currency' => 'eur',
                'metadata' => ['booking' => $this->booking->public_id, 'purpose' => 'booking', 'user' => (string) $this->lucas->id],
                'payment_intent' => ['id' => 'pi_test_9', 'object' => 'payment_intent', 'payment_method' => 'pm_card_visa', 'amount_received' => 4800],
            ]));
        });
    }

    /** @param array<string, mixed> $object */
    private function notify(string $type, array $object, ?string $secret = null): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.md5($type.json_encode($object)), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret ?? self::SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function paid(): Payment
    {
        Cache::put('checkout:cs_test_9', ['purpose' => 'booking', 'user' => $this->lucas->id, 'items' => [], 'guests' => [], 'open' => 0, 'level_min' => null, 'level_max' => null], now()->addDay());
        $this->notify('checkout.session.completed', ['id' => 'cs_test_9', 'object' => 'checkout.session'])->assertOk();

        return Payment::where('provider_reference', 'pi_test_9')->sole();
    }

    public function test_a_wrong_signature_is_refused_without_csrf_token(): void
    {
        $this->notify('checkout.session.completed', ['id' => 'cs_test_9', 'object' => 'checkout.session'], 'whsec_faux')->assertStatus(400);
        $this->assertSame('pending_payment', $this->booking->fresh()->status);
    }

    public function test_the_notification_confirms_a_player_who_closed_the_tab_once_only(): void
    {
        $payment = $this->paid();

        $this->assertSame([4800, 'confirmed'], [$payment->amount_cents, $this->booking->fresh()->status]);
        // Stripe renvoie la même notification : rien n'est refait.
        $this->notify('checkout.session.completed', ['id' => 'cs_test_9', 'object' => 'checkout.session'])->assertOk();
        $this->assertSame(1, Payment::count());
    }

    public function test_a_bank_dispute_freezes_the_booking_and_flags_the_account_without_sanction(): void
    {
        $this->paid();
        $dispute = ['id' => 'dp_test_1', 'object' => 'dispute', 'payment_intent' => 'pi_test_9', 'amount' => 4800, 'reason' => 'fraudulent', 'status' => 'needs_response'];

        $this->notify('charge.dispute.created', $dispute)->assertOk();
        $this->notify('charge.dispute.created', $dispute)->assertOk();

        $this->assertSame('frozen', $this->booking->fresh()->status);
        $this->assertSame(['open', 1], [Chargeback::sole()->status, FraudFlag::count()]);
        $flag = FraudFlag::sole();
        $this->assertSame(['chargeback', 'open'], [$flag->type, $flag->status]);
        // Signalé, jamais suspendu automatiquement.
        $this->assertSame('active', $this->lucas->fresh()->status);

        // Le gérant voit le signalement et le classe.
        $marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
        $this->actingAs($marc)->get('/admin')->assertInertia(fn ($page) => $page->where('flags.0.type', 'chargeback'));
        $this->actingAs($marc)->post("/admin/flags/{$flag->id}/review", ['status' => 'dismissed'])->assertSessionHasNoErrors();
        $this->assertSame(['dismissed', $marc->id], [$flag->fresh()->status, $flag->fresh()->reviewed_by]);
        $this->actingAs($this->lucas)->post("/admin/flags/{$flag->id}/review", ['status' => 'actioned'])->assertForbidden();
    }

    public function test_a_won_dispute_confirms_again_and_a_lost_one_closes_the_booking(): void
    {
        $this->paid();
        $this->notify('charge.dispute.created', ['id' => 'dp_test_2', 'object' => 'dispute', 'payment_intent' => 'pi_test_9', 'amount' => 4800, 'reason' => 'general', 'status' => 'needs_response']);
        $this->notify('charge.dispute.closed', ['id' => 'dp_test_2', 'object' => 'dispute', 'payment_intent' => 'pi_test_9', 'amount' => 4800, 'reason' => 'general', 'status' => 'won'])->assertOk();
        $this->assertSame(['won', 'confirmed'], [Chargeback::sole()->status, $this->booking->fresh()->status]);

        $this->notify('charge.dispute.created', ['id' => 'dp_test_3', 'object' => 'dispute', 'payment_intent' => 'pi_test_9', 'amount' => 4800, 'reason' => 'general', 'status' => 'needs_response']);
        $this->notify('charge.dispute.closed', ['id' => 'dp_test_3', 'object' => 'dispute', 'payment_intent' => 'pi_test_9', 'amount' => 4800, 'reason' => 'general', 'status' => 'lost'])->assertOk();
        $this->assertSame('cancelled_refunded', $this->booking->fresh()->status);
    }
}
