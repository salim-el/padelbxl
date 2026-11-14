<?php

namespace App\Console\Commands;

use App\Services\AccountStatusService;
use Illuminate\Console\Command;

class LiftSuspensions extends Command
{
    protected $signature = 'accounts:lift-suspensions';

    protected $description = 'Réactive les comptes dont la suspension à durée fixe est terminée (F28)';

    public function handle(AccountStatusService $accounts): int
    {
        $this->info($accounts->liftExpired().' suspension(s) levée(s).');

        return self::SUCCESS;
    }
}
