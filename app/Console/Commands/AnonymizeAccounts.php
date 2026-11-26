<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AnonymizeAccounts extends Command
{
    protected $signature = 'accounts:anonymize';

    protected $description = 'Anonymise les comptes désactivés depuis plus de trente jours (les factures gardent leur copie du nom)';

    public function handle(): int
    {
        $count = 0;
        User::onlyTrashed()->whereNull('anonymized_at')->where('deactivated_at', '<=', now()->subDays(30))
            ->each(function (User $user) use (&$count) {
                $user->anonymize();
                $count++;
            });
        $this->info("$count compte(s) anonymisé(s).");

        return self::SUCCESS;
    }
}
