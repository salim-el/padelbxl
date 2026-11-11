<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Booking;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
