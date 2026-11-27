<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Une base déjà en service (Render) a été semée avant le foot : elle reçoit ici les 4 terrains et les tarifs du foot.
    // Une base neuve n'a encore aucun terrain : DatabaseSeeder sème le foot, la migration ne fait rien.
    public function up(): void
    {
        if (! DB::table('courts')->exists() || DB::table('courts')->where('sport', 'foot5')->exists()) {
            return;
        }
        $now = now();
        foreach (range(1, 4) as $i) {
            DB::table('courts')->insert(['name' => "Foot $i", 'sport' => 'foot5', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DatabaseSeeder::FOOT_RULES as $rule) {
            DB::table('pricing_rules')->insert($rule + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    // Avant de retirer la colonne sport (migration 000001), le foot doit partir : il deviendrait sinon du padel.
    // Les terrains d'abord : une réservation de foot les retient (clé étrangère) et le retour en arrière s'arrête sans rien effacer.
    public function down(): void
    {
        DB::table('courts')->where('sport', 'foot5')->delete();
        DB::table('pricing_rules')->where('sport', 'foot5')->delete();
    }
};
