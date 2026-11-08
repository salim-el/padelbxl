<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;

class ChargeUnpaidShares extends Command
{
    protected $signature = 'bookings:charge-unpaid-shares';

    protected $description = 'À 24 h du match, fait payer à l’organisateur les parts que ses invités n’ont pas réglées';

    public function handle(BookingService $bookings): int
    {
        $this->info($bookings->chargeUnpaidShares().' part(s) réglée(s) par l’organisateur.');

        return self::SUCCESS;
    }
}
