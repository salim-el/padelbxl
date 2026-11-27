<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Les conditions générales changent avec le foot à 5 : une base déjà en service (Render) reçoit ici leur version 2026.2.
    // Chaque joueur l'accepte avant sa prochaine réservation (F7). Une base neuve n'a encore aucun document : DatabaseSeeder la publie.
    public function up(): void
    {
        if (! DB::table('legal_documents')->exists()) {
            return;
        }
        // En vigueur au déploiement, jamais avant : un joueur inscrit entre-temps a bien accepté la version 2026.1, alors en vigueur.
        foreach (DatabaseSeeder::TERMS_2026_2 as $locale => $content) {
            DB::table('legal_documents')->insertOrIgnore(['type' => 'terms', 'version' => '2026.2', 'locale' => $locale, 'content' => $content, 'published_at' => now()]);
        }
    }

    // Une version déjà acceptée reste : la clé étrangère des consentements arrête le retour en arrière sans rien effacer.
    public function down(): void
    {
        DB::table('legal_documents')->where('type', 'terms')->where('version', '2026.2')->delete();
    }
};
