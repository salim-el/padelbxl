<?php

namespace Tests\Feature\Padel;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Notifications\ShareDeclined;
use App\Notifications\ShareInvitation;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\SlotService;
use App\Services\StoreCreditService;
use App\Services\StripePayments;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/** Foot à 5 en salle : 4 terrains à côté des 6 de padel, des créneaux d'une heure, 10 joueurs et ses propres tarifs. */
class FootballTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private User $marc;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        // Trois jours avant les créneaux testés : aucun n'a la remise flash.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
        $this->service = app(BookingService::class);
    }

    private function court(string $name): Court
    {
        return Court::where('name', $name)->firstOrFail();
    }

    private function hold(string $court, string $time, string $day = '2026-10-23'): Booking
    {
        return $this->service->hold($this->lucas, $this->court($court), Clock::slotStart($day, $time));
    }

    private function confirmed(string $court, string $time, string $day = '2026-10-23'): Booking
    {
        return $this->service->confirmPayment($this->hold($court, $time, $day), $this->lucas);
    }

    private function refused(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Refus attendu : '.$message);
        } catch (BookingException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_the_club_has_six_padel_courts_then_four_football_courts(): void
    {
        $this->assertSame(['Terrain 1', 'Terrain 2', 'Terrain 3', 'Terrain 4', 'Terrain 5', 'Terrain 6', 'Foot 1', 'Foot 2', 'Foot 3', 'Foot 4'],
            Court::orderBy('id')->pluck('name')->all());
        $this->assertSame(['padel' => 6, 'foot5' => 4], Court::orderBy('id')->pluck('sport')->countBy()->all());
        $this->assertSame([7000, 9000], PricingRule::where('sport', 'foot5')->orderBy('start_time')->pluck('price_cents')->all());
        // La raquette et les balles restent du padel.
        $this->assertSame(['padel'], Product::distinct()->pluck('sport')->all());
    }

    public function test_the_football_planning_has_four_courts_and_fourteen_one_hour_slots(): void
    {
        $hours = array_map(fn (int $h) => sprintf('%02d:00', $h), range(9, 22));

        $this->get('/planning?date=2026-10-23&sport=foot5')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('planning')
            ->where('sport', 'foot5')
            ->where('sports', ['padel', 'foot5'])
            ->where('courts', fn ($courts) => $courts->pluck('name')->all() === ['Foot 1', 'Foot 2', 'Foot 3', 'Foot 4'])
            ->where('rows', fn ($rows) => $rows->pluck('time')->all() === $hours)
            ->where('rows.0.end', '10:00')
            ->where('rows.13.end', '23:00')
            // Heures creuses jusqu'à 17 h : 70 € le créneau de 16 h, 90 € celui de 17 h.
            ->where('rows', fn ($rows) => $rows->every(fn ($row) => collect($row['cells'])->pluck('price_cents')->unique()->all() === [$row['time'] < '17:00' ? 7000 : 9000]))
            ->where('rows.0.cells.0.flash', false));
    }

    public function test_the_default_planning_stays_padel(): void
    {
        $this->get('/planning?date=2026-10-23')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sport', 'padel')
            ->where('courts', fn ($courts) => $courts->pluck('name')->all() === ['Terrain 1', 'Terrain 2', 'Terrain 3', 'Terrain 4', 'Terrain 5', 'Terrain 6'])
            ->where('rows', fn ($rows) => $rows->pluck('time')->all() === ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30', '18:00', '19:30', '21:00'])
            ->where('rows.0.end', '10:30')
            ->where('rows.0.cells.0.price_cents', 3360)
            ->where('rows.6.cells.0.price_cents', 4800));
        $this->get('/planning?date=2026-10-23&sport=squash')->assertSessionHasErrors('sport');

        // Sans terrain de foot actif, le planning ne propose plus que le padel.
        Court::where('sport', 'foot5')->update(['is_active' => false]);
        $this->get('/planning?date=2026-10-23')->assertInertia(fn (Assert $page) => $page->where('sports', ['padel']));
    }

    public function test_while_moving_the_planning_shows_the_courts_of_the_booking_sport(): void
    {
        $booking = $this->confirmed('Foot 1', '17:00');

        $this->actingAs($this->lucas)->get("/planning?date=2026-10-24&move={$booking->public_id}&sport=padel")
            ->assertInertia(fn (Assert $page) => $page->where('sport', 'foot5')->where('moving.court', 'Foot 1')->has('courts', 4));
    }

    public function test_a_football_court_is_held_for_one_hour_at_the_football_price(): void
    {
        $booking = $this->hold('Foot 1', '17:00');

        $this->assertSame('18:00', Clock::local($booking->ends_at)->format('H:i'));
        $this->assertSame(9000, $booking->price_cents);
        $this->assertSame('6.00', $booking->fresh()->vat_rate);
        // 16 h reste en heures creuses.
        $this->assertSame(7000, $this->hold('Foot 2', '16:00')->price_cents);
    }

    public function test_each_sport_keeps_its_own_start_times(): void
    {
        $this->refused(fn () => $this->hold('Terrain 1', '10:00'), 'n’existe pas');
        $this->refused(fn () => $this->hold('Foot 1', '10:30'), 'n’existe pas');
    }

    public function test_up_to_nine_guests_share_a_football_match(): void
    {
        $booking = $this->hold('Foot 1', '17:00');
        $guests = array_map(fn (int $i) => "joueur$i@example.test", range(1, 10));
        $pay = fn (array $guests) => $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'accept_guarantee' => '1', 'guests' => $guests]);

        $pay($guests)->assertSessionHasErrors('guests');
        $pay(array_slice($guests, 0, 9))->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $booking->fresh()->status);
        // 90 € pour dix joueurs : 9 € chacun.
        $this->assertSame(array_fill(0, 10, 900), $booking->players()->orderBy('id')->pluck('share_cents')->all());
    }

    public function test_sharing_limits_follow_the_sport(): void
    {
        $foot = $this->hold('Foot 1', '17:00');
        $padel = $this->hold('Terrain 1', '19:30');
        $emails = fn (int $n) => array_map(fn (int $i) => "joueur$i@example.test", range(1, $n));

        $this->refused(fn () => $this->service->confirmPayment($foot, $this->lucas, guests: $emails(10)), 'Invitez au plus 9 autres joueurs');
        $this->refused(fn () => $this->service->confirmPayment($padel, $this->lucas, guests: $emails(4)), 'Invitez au plus 3 autres joueurs');
        // L'appel aux membres suit l'échelle de niveau du padel : au foot, aucune place ouverte.
        $this->refused(fn () => $this->service->confirmPayment($foot, $this->lucas, openSpots: 1), 'seulement le padel');
        $this->assertSame('pending_payment', $foot->fresh()->status);
    }

    public function test_a_padel_article_is_refused_on_a_football_booking(): void
    {
        $towel = Product::create(['name' => 'Location de serviette', 'kind' => 'rental', 'price_cents' => 200, 'vat_rate' => 21, 'stock' => 20]);
        $racket = Product::where('name', 'Location de raquette')->firstOrFail();
        $foot = $this->hold('Foot 1', '17:00');
        $padel = $this->hold('Terrain 1', '19:30');

        // Un article sans sport est proposé partout ; la raquette, seulement au padel.
        $this->actingAs($this->lucas)->get("/bookings/{$foot->public_id}/checkout")->assertInertia(fn (Assert $page) => $page
            ->where('sport', 'foot5')->where('max_guests', 9)
            ->where('products', fn ($products) => $products->pluck('name')->all() === ['Location de serviette']));
        $this->actingAs($this->lucas)->get("/bookings/{$padel->public_id}/checkout")->assertInertia(fn (Assert $page) => $page
            ->where('sport', 'padel')->where('max_guests', 3)->has('products', 3));
        $this->refused(fn () => $this->service->confirmPayment($foot, $this->lucas, [$racket->id => 1]), 'pas proposé pour ce sport');

        $this->assertSame('confirmed', $this->service->confirmPayment($foot, $this->lucas, [$towel->id => 1])->status);
    }

    public function test_with_stripe_a_request_the_service_refuses_never_reaches_the_card(): void
    {
        // Place ouverte ou raquette au foot : refusées par le service, elles ne doivent rien débiter chez Stripe.
        $this->mock(StripePayments::class, function (MockInterface $mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldNotReceive('checkout');
        });
        $racket = Product::where('name', 'Location de raquette')->firstOrFail();
        $foot = $this->hold('Foot 1', '17:00');
        $pay = fn (array $extra) => $this->actingAs($this->lucas)->post("/bookings/{$foot->public_id}/pay", ['accept_terms' => '1', ...$extra]);

        $pay(['accept_guarantee' => '1', 'open_spots' => 2, 'level_min' => 3, 'level_max' => 4])
            ->assertSessionHasErrors(['booking' => __('Les places ouvertes aux membres concernent seulement le padel.')]);
        $pay(['items' => [$racket->id => 1]])->assertSessionHasErrors(['booking' => __('Cet article n’est pas proposé pour ce sport.')]);

        $this->assertSame('pending_payment', $foot->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_declined_football_invitation_is_not_offered_to_the_members(): void
    {
        $foot = $this->service->confirmPayment($this->hold('Foot 1', '17:00'), $this->lucas, guests: [$this->marc->email]);

        $this->actingAs($this->marc)->post("/bookings/{$foot->public_id}/share/decline")
            ->assertSessionHas('success', __('Invitation refusée. Votre part reste à la charge de l’organisateur.'));

        // Pas de place ouverte au foot : la partie n'est ni listée ni joignable, même par la liste d'attente.
        $this->assertFalse(BookingService::openMatches()->whereKey($foot->id)->exists());
        $member = User::factory()->create();
        $this->refused(fn () => $this->service->joinOpenMatch($foot, $member), 'n’accepte plus de joueurs');
        $this->refused(fn () => $this->service->joinWaitlist($foot, $member), 'n’accepte plus de joueurs');

        // À 24 h du match, la garantie de l'organisateur paie la part refusée.
        $this->travelTo($foot->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS));
        $this->service->chargeUnpaidShares();
        $this->assertSame(['paid', 'covered'], $foot->players()->orderBy('id')->pluck('status')->all());
    }

    public function test_the_organizer_learns_that_the_declined_football_share_is_his(): void
    {
        $foot = $this->service->confirmPayment($this->hold('Foot 1', '17:00'), $this->lucas, guests: [$this->marc->email]);
        $padel = $this->service->confirmPayment($this->hold('Terrain 1', '19:30'), $this->lucas, guests: [$this->marc->email]);
        $this->actingAs($this->marc)->post("/bookings/{$foot->public_id}/share/decline");
        $this->actingAs($this->marc)->post("/bookings/{$padel->public_id}/share/decline");

        // Au foot, la ligne refusée n'est pas une place ouverte : elle reste à l'organisateur, prévenu par e-mail.
        $this->actingAs($this->lucas)->get("/bookings/{$foot->public_id}")->assertInertia(fn (Assert $page) => $page
            ->where('players.1.open', false)->where('players.1.declined', true));
        Notification::assertSentToTimes($this->lucas, ShareDeclined::class, 1);
        Notification::assertSentTo($this->lucas, ShareDeclined::class, function (ShareDeclined $n) {
            $lines = implode(' ', $n->toMail($this->lucas)->introLines);

            return str_contains($lines, 'marc.dumont@example.test') && str_contains($lines, '45,00 €');
        });
        // Le padel ne change pas : la place refusée est proposée aux membres.
        $this->actingAs($this->lucas)->get("/bookings/{$padel->public_id}")->assertInertia(fn (Assert $page) => $page
            ->where('players.1.open', true)->where('players.1.declined', false));
    }

    public function test_with_stripe_a_shared_football_match_needs_a_guarantee_card(): void
    {
        // L'avoir de Lucas couvre sa part de 9 € : sans carte gardée, la garantie ne pourrait pas payer les neuf autres parts.
        $this->mock(StripePayments::class, function (MockInterface $mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldNotReceive('checkout');
        });
        app(StoreCreditService::class)->grant($this->lucas, 1000, 'goodwill');
        $foot = $this->hold('Foot 1', '17:00');
        $guests = array_map(fn (int $i) => "joueur$i@example.test", range(1, 9));

        $this->actingAs($this->lucas)->post("/bookings/{$foot->public_id}/pay", ['accept_terms' => '1', 'accept_guarantee' => '1', 'guests' => $guests])
            ->assertSessionHasErrors(['booking' => __('Un match de foot partagé se garantit par une carte. Votre avoir paie déjà toute votre part : réservez sans partager le prix.')]);

        $this->assertSame('pending_payment', $foot->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_failing_invitation_email_neither_breaks_the_payment_nor_stops_the_others(): void
    {
        // Vrai envoi (transport array) à la place du faux de setUp ; l'envoi à joueur1 tombe en panne.
        Notification::swap(new ChannelManager($this->app));
        Event::listen(NotificationSending::class, function (NotificationSending $e) {
            if (($e->notifiable->routes['mail'] ?? null) === 'joueur1@example.test') {
                throw new RuntimeException('Serveur SMTP indisponible.');
            }
        });
        $sent = 0;
        Event::listen(NotificationSent::class, function () use (&$sent) {
            $sent++;
        });
        $booking = $this->hold('Foot 1', '17:00');
        $guests = array_map(fn (int $i) => "joueur$i@example.test", range(1, 9));

        $this->actingAs($this->lucas)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'accept_guarantee' => '1', 'guests' => $guests])
            ->assertRedirect("/bookings/{$booking->public_id}")->assertSessionHas('success');

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(8, $sent);
    }

    public function test_a_refund_retried_after_a_stripe_failure_reuses_its_idempotency_key(): void
    {
        $keys = [];
        $calls = 0;
        $this->mock(StripePayments::class, function (MockInterface $mock) use (&$keys, &$calls) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('refund')->andReturnUsing(function (string $intent, int $amount, string $key) use (&$keys, &$calls) {
                $keys[$intent][] = $key;
                // Panne au deuxième remboursement : le premier est déjà fait chez Stripe, la base revient en arrière.
                if (++$calls === 2) {
                    throw new BookingException('Le remboursement n’a pas abouti. Réessayez dans un instant.');
                }

                return 're_test_'.$calls;
            });
        });
        $service = app(BookingService::class);
        $ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $foot = $service->confirmPayment($this->hold('Foot 1', '17:00'), $this->lucas, [], 'card', [$this->marc->email, $ines->email], 0, 'pi_lucas', 'pm_card_visa');
        $service->payShare($foot, $this->marc, 'pi_marc');
        $service->payShare($foot, $ines, 'pi_ines');

        $this->refused(fn () => $service->cancel($foot, $this->lucas), 'n’a pas abouti');
        $this->assertSame('confirmed', $foot->fresh()->status);
        $service->cancel($foot, $this->lucas);

        $this->assertSame('cancelled_refunded', $foot->fresh()->status);
        // Rejouée avec la même clé, la demande déjà faite chez Stripe n'y rembourse pas une seconde fois.
        $this->assertSame(5, $calls);
        foreach ($keys as $intent => $list) {
            $this->assertCount(1, array_unique($list), "Clés différentes pour $intent : ".implode(', ', $list));
        }
    }

    public function test_a_club_with_only_football_opens_the_football_planning(): void
    {
        // Cas miroir du padel seul : sans terrain de padel actif, le planning montre le foot et non un padel vide.
        Court::where('sport', 'padel')->update(['is_active' => false]);

        $this->get('/planning?date=2026-10-23')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sports', ['foot5'])->where('sport_chosen', true)->where('sport', 'foot5')->has('courts', 4));
    }

    public function test_the_invitation_email_names_a_football_match(): void
    {
        $foot = $this->service->confirmPayment($this->hold('Foot 1', '17:00'), $this->lucas, guests: [$this->marc->email]);

        Notification::assertSentTo($this->marc, ShareInvitation::class,
            fn (ShareInvitation $n) => $n->toMail($this->marc)->subject === 'Lucas Martin vous invite à un match de foot à 5');
        // Les clés reprennent la phrase française mot pour mot : une clé mal recopiée laisserait le sujet en français.
        foreach (['en' => 'Lucas Martin invites you to a 5-a-side football match', 'nl' => 'Lucas Martin nodigt je uit voor een minivoetbalmatch'] as $locale => $subject) {
            app()->setLocale($locale);
            $this->assertSame($subject, (new ShareInvitation($foot, 'Lucas Martin', 900))->toMail($this->marc)->subject);
        }
    }

    public function test_the_daily_invitation_quota_follows_the_sport(): void
    {
        $emails = fn (string $p, int $n) => array_map(fn (int $i) => "$p$i@example.test", range(1, $n));

        // Quatre parties partagées par jour et par sport : 12 invitations au padel (inchangé), 36 au foot.
        $this->assertSame(12, BookingService::MAX_SHARED_GAMES_PER_DAY * (Court::PLAYERS['padel'] - 1));
        $this->service->confirmPayment($this->hold('Foot 1', '17:00'), $this->lucas, guests: $emails('a', 9));
        $this->assertSame('confirmed', $this->service->confirmPayment($this->hold('Foot 2', '17:00', '2026-10-24'), $this->lucas, guests: $emails('b', 9))->status);
        // Les invitations du foot ne mangent pas le quota du padel.
        $this->assertSame('confirmed', $this->service->confirmPayment($this->hold('Terrain 1', '19:30'), $this->lucas, guests: $emails('c', 3))->status);
    }

    public function test_a_booking_moves_only_within_its_sport(): void
    {
        $padel = $this->confirmed('Terrain 1', '10:30');
        $to = Clock::slotStart('2026-10-24', '18:00');

        // Refusé de toute façon : aucun supplément n'est demandé à la carte.
        $this->assertSame(0, $this->service->moveAmount($padel, $this->lucas, $this->court('Foot 1'), $to));
        $this->refused(fn () => $this->service->move($padel, $this->lucas, $this->court('Foot 1'), $to), 'même sport');

        // D'un terrain de foot à l'autre : 90 € en heures pleines au lieu de 70 €, toujours une heure.
        $foot = $this->confirmed('Foot 1', '10:00');
        $this->assertSame(2000, $this->service->moveAmount($foot, $this->lucas, $this->court('Foot 2'), $to));
        $moved = $this->service->move($foot, $this->lucas, $this->court('Foot 2'), $to);
        $this->assertSame(9000, $moved->price_cents);
        $this->assertSame('19:00', Clock::local($moved->ends_at)->format('H:i'));
    }

    public function test_occupancy_counts_the_slots_of_each_sport(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));
        // Un terrain de chaque sport : 9 créneaux de padel et 14 de foot, soit 23.
        Court::whereNotIn('name', ['Terrain 1', 'Foot 1'])->update(['is_active' => false]);
        $this->confirmed('Terrain 1', '18:00');
        $this->confirmed('Foot 1', '20:00');

        $this->actingAs($this->marc)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('stats.bookings_today', 2)
            ->where('stats.occupancy', 9)
            ->where('courts.6.name', 'Foot 1')
            ->where('courts.6.sport', 'foot5')
            ->where('rules', fn ($rules) => $rules->pluck('sport')->countBy()->sortKeys()->all() === ['foot5' => 2, 'padel' => 2]));
    }

    public function test_the_manager_adds_a_football_rate_on_the_hours_of_a_padel_rate(): void
    {
        $rate = ['name' => 'Heures creuses hiver', 'start_time' => '09:00', 'end_time' => '17:00', 'price_cents' => 3000, 'vat_rate' => 6, 'valid_from' => '2026-12-01'];
        $create = fn (array $data) => $this->actingAs($this->marc)->post('/admin/pricing-rules', $data);

        $create($rate)->assertSessionHasNoErrors();
        // Mêmes heures et même jour qu'un tarif de padel : pas de conflit pour un autre sport.
        $create(['sport' => 'foot5', 'price_cents' => 6000] + $rate)->assertSessionHasNoErrors();
        $create(['sport' => 'foot5', 'name' => 'Midi', 'start_time' => '12:00', 'end_time' => '14:00'] + $rate)->assertSessionHasErrors('pricing');
        $create(['sport' => 'squash'] + $rate)->assertSessionHasErrors('sport');

        $this->assertSame(['padel', 'foot5'], PricingRule::where('name', 'Heures creuses hiver')->orderBy('id')->pluck('sport')->all());
        $slots = app(SlotService::class);
        $this->assertSame(3000, $slots->priceFor(Clock::slotStart('2026-12-01', '10:30'))->price_cents);
        $this->assertSame(6000, $slots->priceFor(Clock::slotStart('2026-12-01', '10:00'), 'foot5')->price_cents);
    }

    public function test_settings_measure_each_booking_against_the_slots_of_its_sport(): void
    {
        $this->confirmed('Foot 1', '10:00');
        $settings = ['opens_at' => '09:00', 'closes_at' => '23:00', 'slot_minutes' => 120];

        // Créneaux de padel de deux heures : le match de foot de 10 h à 11 h reste sur une heure du foot.
        $this->actingAs($this->marc)->put('/admin/settings', $settings)->assertSessionHasNoErrors();
        // Ouvrir à 9 h 30 décale les heures du foot : 10 h n'en est plus une.
        $this->actingAs($this->marc)->put('/admin/settings', ['opens_at' => '09:30'] + $settings)->assertSessionHasErrors('settings');
    }

    public function test_the_api_gives_the_sport_of_each_court_and_the_football_availability(): void
    {
        $this->getJson('/api/v1/courts')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.sport', 'padel')
            ->assertJsonPath('data.6.name', 'Foot 1')
            ->assertJsonPath('data.6.sport', 'foot5');
        $this->getJson('/api/v1/availability?date=2026-10-23&sport=foot5')->assertOk()
            ->assertJsonPath('sport', 'foot5')->assertJsonCount(4, 'courts')->assertJsonCount(14, 'rows')
            ->assertJsonPath('rows.8.time', '17:00')->assertJsonPath('rows.8.cells.0.price_cents', 9000);
        $this->getJson('/api/v1/availability?date=2026-10-23')->assertOk()->assertJsonPath('sport', 'padel')->assertJsonCount(6, 'courts');
        $this->getJson('/api/v1/availability?date=2026-10-23&sport=squash')->assertUnprocessable()->assertJsonValidationErrors('sport');
    }

    public function test_the_migration_adds_the_football_to_a_padel_only_database_once(): void
    {
        $migration = require database_path('migrations/2026_11_26_000002_add_football_courts.php');
        // Base en ligne semée avant le foot : padel seul.
        Court::where('sport', 'foot5')->delete();
        PricingRule::where('sport', 'foot5')->delete();

        $migration->up();
        $migration->up();

        $this->assertSame(['Foot 1', 'Foot 2', 'Foot 3', 'Foot 4'], Court::where('sport', 'foot5')->orderBy('id')->pluck('name')->all());
        $this->assertSame([7000, 9000], PricingRule::where('sport', 'foot5')->orderBy('start_time')->pluck('price_cents')->all());
        $this->assertSame(6, Court::where('sport', 'padel')->count());

        $migration->down();
        $this->assertSame(0, Court::where('sport', 'foot5')->count() + PricingRule::where('sport', 'foot5')->count());

        // Base neuve, sans terrain : DatabaseSeeder sèmera le foot, la migration n'ajoute rien.
        Court::query()->delete();
        $migration->up();
        $this->assertSame(0, Court::count());
    }
}
