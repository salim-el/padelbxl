<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\Product;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EquipmentRentalTest extends TestCase
{
    use RefreshDatabase;

    private User $lucas;

    private Product $racket;

    private Product $balls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
        $this->racket = Product::where('kind', 'rental')->first();
        $this->balls = Product::where('kind', 'sale')->first();
    }

    private function book(User $user, int $court, array $items)
    {
        $booking = app(BookingService::class)->hold($user, Court::where('name', "Terrain $court")->first(), Clock::slotStart('2026-10-23', '19:30'));
        $response = $this->actingAs($user)->post("/bookings/{$booking->public_id}/pay", ['accept_terms' => '1', 'items' => $items]);

        return [$booking->refresh(), $response];
    }

    public function test_the_invoice_has_one_line_per_vat_rate_and_the_payment_covers_everything(): void
    {
        [$booking] = $this->book($this->lucas, 1, [$this->racket->id => 2, $this->balls->id => 1]);
        $invoice = DB::table('invoices')->where('booking_id', $booking->id)->first();
        $this->assertSame(4800 + 800 + 600, $invoice->total_cents);
        $this->assertSame(4800 + 800 + 600, DB::table('payments')->where('booking_id', $booking->id)->value('amount_cents'));
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $this->assertCount(3, $lines);
        $this->assertSame('6.00', $lines[0]->vat_rate);
        $this->assertSame('21.00', $lines[1]->vat_rate);
        // 8,00 € TTC à 21 % : 6,61 € hors TVA et 1,39 € de TVA.
        $this->assertSame(661, $lines[1]->net_cents);
        $this->assertSame($invoice->vat_cents, (int) $lines->sum('vat_cents'));
    }

    public function test_rental_stock_is_counted_per_slot(): void
    {
        $this->racket->update(['stock' => 3]);
        $this->book($this->lucas, 1, [$this->racket->id => 2]);
        $ines = User::where('email', 'ines.laurent@example.test')->first();
        [$second, $response] = $this->book($ines, 2, [$this->racket->id => 2]);
        $response->assertSessionHasErrors('booking');
        $this->assertSame('pending_payment', $second->status);
        $this->assertSame(0, DB::table('booking_items')->where('booking_id', $second->id)->count());
    }

    public function test_sold_items_leave_the_stock_and_come_back_on_a_refunded_cancellation(): void
    {
        $this->balls->update(['stock' => 3]);
        [$booking] = $this->book($this->lucas, 1, [$this->balls->id => 2]);
        $this->assertSame(1, $this->balls->refresh()->stock);

        $ines = User::where('email', 'ines.laurent@example.test')->first();
        [, $response] = $this->book($ines, 2, [$this->balls->id => 2]);
        $response->assertSessionHasErrors('booking');
        $this->assertSame(1, $this->balls->refresh()->stock);

        app(BookingService::class)->cancel($booking, $this->lucas);
        $this->assertSame(3, $this->balls->refresh()->stock);
    }

    public function test_more_than_four_of_an_item_is_refused(): void
    {
        [$booking, $response] = $this->book($this->lucas, 1, [$this->racket->id => 5]);
        $response->assertSessionHasErrors('items.'.$this->racket->id);
        $this->assertSame('pending_payment', $booking->status);
    }

    public function test_an_invoice_line_cannot_be_changed(): void
    {
        $this->book($this->lucas, 1, [$this->balls->id => 1]);
        $this->expectException(QueryException::class);
        DB::table('invoice_lines')->update(['total_cents' => 1]);
    }
}
