<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AnonymizeAccounts extends Command
{
    protected $signature = 'accounts:anonymize';

    protected $description = 'Anonymise les comptes désactivés depuis plus de trente jours (les factures gardent leur copie du nom)';

    public function handle(): int
    {
        $count = 0;
        User::onlyTrashed()->whereNull('anonymized_at')->where('deactivated_at', '<=', now()->subDays(30))
            ->each(function (User $user) use (&$count) {
                $user->forceFill([
                    'name' => 'Compte supprimé',
                    'email' => "supprime-{$user->id}@padelbxl.invalid",
                    'phone' => null,
                    'account_type' => 'individual',
                    'company_name' => null,
                    'vat_number' => null,
                    'password' => Str::random(64),
                    'remember_token' => null,
                    'status' => 'anonymized',
                    'anonymized_at' => now(),
                ])->saveQuietly();
                $count++;
            });
        $this->info("$count compte(s) anonymisé(s).");

        return self::SUCCESS;
    }
}
