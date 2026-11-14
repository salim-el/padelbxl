<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyAccountTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $data)
    {
        return $this->post('/register', $data + [
            'name' => 'Nora Vermeulen',
            'email' => 'nora@atelier-nova.test',
            'password' => 'mot-de-passe-long-2026',
            'password_confirmation' => 'mot-de-passe-long-2026',
            'birth_date' => '1995-04-12',
            'level' => 3,
            'accept_terms' => true,
        ]);
    }

    public function test_a_company_registers_with_its_name_and_a_normalised_vat_number(): void
    {
        $this->register(['account_type' => 'company', 'company_name' => 'Atelier Nova SRL', 'vat_number' => 'be 0123.456.749'])->assertSessionHasNoErrors();
        $user = User::where('email', 'nora@atelier-nova.test')->firstOrFail();
        $this->assertSame('company', $user->account_type);
        $this->assertSame('Atelier Nova SRL', $user->company_name);
        $this->assertSame('BE0123456749', $user->vat_number);
    }

    public function test_a_wrong_vat_number_or_a_missing_company_name_is_refused(): void
    {
        $this->register(['account_type' => 'company', 'company_name' => 'Atelier Nova SRL', 'vat_number' => 'BE0123456748'])->assertSessionHasErrors('vat_number');
        $this->register(['account_type' => 'company', 'vat_number' => 'BE0123456749'])->assertSessionHasErrors('company_name');
        $this->assertGuest();
    }

    public function test_an_individual_keeps_no_company_data(): void
    {
        $this->register(['account_type' => 'individual', 'company_name' => 'Ignorée', 'vat_number' => 'BE0123456749'])->assertSessionHasNoErrors();
        $user = User::where('email', 'nora@atelier-nova.test')->firstOrFail();
        $this->assertNull($user->company_name);
        $this->assertNull($user->vat_number);
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['vat_number' => 'BE0123456749']);
    }

    public function test_the_invoice_carries_the_company_and_its_vat_number(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $user = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $user->forceFill(['account_type' => 'company', 'company_name' => 'Atelier Nova SRL', 'vat_number' => 'BE0123456749'])->save();
        $service = app(BookingService::class);
        $booking = $service->confirmPayment($service->hold($user, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30')), $user);
        $invoice = DB::table('invoices')->where('booking_id', $booking->id)->first();
        $this->assertSame('Atelier Nova SRL', $invoice->customer_company);
        $this->assertSame('BE0123456749', $invoice->customer_vat);
    }
}
