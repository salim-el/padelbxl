<?php

namespace App\Console\Commands;

use App\Models\AccessLog;
use Illuminate\Console\Command;

class PruneAccessLogs extends Command
{
    /** Durée inscrite au registre des traitements (T08, T18) : assez pour traiter un litige, pas plus. */
    public const MONTHS = 3;

    protected $signature = 'access-logs:prune';

    protected $description = 'Efface les passages à la borne de plus de trois mois (minimisation RGPD)';

    public function handle(): int
    {
        $count = AccessLog::where('scanned_at', '<', now()->subMonths(self::MONTHS))->delete();
        $this->info("$count passage(s) effacé(s).");

        return self::SUCCESS;
    }
}
