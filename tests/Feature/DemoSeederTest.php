<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingPlayer;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Clock;
use App\Services\LegalConsents;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_fills_every_business_table_and_puts_the_clock_back(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertNull(Carbon::getTestNow());
        $this->assertGreaterThan(20, Invoice::count());
        $this->assertGreaterThanOrEqual(3, CreditNote::count());
        $this->assertGreaterThan(10, AccessLog::where('result', 'granted')->count());
        $this->assertTrue(AccessLog::where('result', 'refused')->exists());
        foreach (['bookings', 'booking_players', 'booking_items', 'booking_status_histories', 'payments', 'invoices', 'invoice_lines', 'credit_notes',
            'refunds', 'access_passes', 'access_logs', 'court_closures', 'waitlist_entries', 'document_sequences', 'activity_log', 'personal_access_tokens'] as $table) {
            $this->assertTrue(DB::table($table)->exists(), "Table $table vide");
        }
        foreach (['used', 'confirmed', 'expired', 'cancelled_refunded', 'cancelled_unrefunded'] as $status) {
            $this->assertTrue(Booking::where('status', $status)->exists(), "Aucune réservation $status");
        }
        $this->assertTrue(Booking::whereNotNull('walk_in_name')->exists());
        $this->assertSame(1, User::onlyTrashed()->count());

        // Facture d'entreprise : le numéro de TVA passe le contrôle modulo 97.
        $vat = Invoice::whereNotNull('customer_vat')->value('customer_vat');
        $this->assertSame(97 - ((int) substr($vat, 2, 8) % 97), (int) substr($vat, 10));
        // La numérotation suit l'ordre des dates d'émission.
        $this->assertSame(Invoice::orderBy('issued_at')->orderBy('id')->pluck('number')->all(), Invoice::orderBy('number')->pluck('number')->all());
    }

    public function test_demo_football_matches_last_one_hour_at_the_football_prices(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $foot = Booking::with('players')->whereHas('court', fn ($q) => $q->where('sport', 'foot5'))->get();
        $past = $foot->filter(fn (Booking $b) => $b->starts_at->isPast());
        $this->assertGreaterThanOrEqual(2, $past->count());
        foreach ($foot as $booking) {
            // Une heure, 70 € avant 17 h et 90 € ensuite : réservés des jours à l'avance, aucun n'a la remise flash.
            $this->assertSame(Court::FOOT_MINUTES, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
            $this->assertSame(Clock::local($booking->starts_at)->format('H:i') < '17:00' ? 7000 : 9000, $booking->price_cents);
            $this->assertSame($booking->price_cents, (int) $booking->players->sum('share_cents'));
        }
        $this->assertSame([7000, 9000], $foot->pluck('price_cents')->unique()->sort()->values()->all());
        // Matchs joués : passés à la borne et payés en entier, factures des parts comprises.
        foreach ($past as $booking) {
            $this->assertSame('used', $booking->status);
            $this->assertSame($booking->price_cents, (int) $booking->invoices()->sum('total_cents'));
        }
        // À venir, à dix : 9 € par joueur.
        $shared = $foot->first(fn (Booking $b) => $b->starts_at->isFuture() && $b->players->count() === Court::PLAYERS['foot5']);
        $this->assertNotNull($shared);
        $this->assertSame(array_fill(0, 10, 900), $shared->players->pluck('share_cents')->all());
        // Ni article de padel, ni place ouverte aux membres.
        $this->assertFalse(BookingItem::whereIn('booking_id', $foot->modelKeys())->exists());
        $this->assertFalse(BookingPlayer::whereIn('booking_id', $foot->modelKeys())->whereNull('user_id')->whereNull('guest_email')->exists());
    }

    public function test_demo_players_have_already_accepted_the_terms_in_force(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        // Conditions du foot à 5 en vigueur : un joueur de la démo, inscrit avant elles, réserve sans passer par leur acceptation.
        $consents = app(LegalConsents::class);
        $this->assertSame('2026.2', $consents->current('fr')->firstWhere('type', 'terms')->version);
        $players = User::where('role', 'player')->get();
        $this->assertGreaterThanOrEqual(10, $players->count());
        foreach ($players as $player) {
            $this->assertTrue($consents->missing($player)->isEmpty(), $player->email);
        }
        $this->actingAs($players->firstWhere('email', 'sarah.janssens@example.test'))->get('/bookings')->assertOk();
    }

    public function test_online_demo_accounts_do_not_use_the_public_password(): void
    {
        // Le mot de passe du dépôt est public : en ligne, celui que Render génère le remplace.
        config(['app.demo_password' => 'genere-par-render']);
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('role', 'admin')->sole()->password;
        $this->assertTrue(Hash::check('genere-par-render', $admin));
        $this->assertFalse(Hash::check(DatabaseSeeder::DEMO_PASSWORD, $admin));
    }
}
