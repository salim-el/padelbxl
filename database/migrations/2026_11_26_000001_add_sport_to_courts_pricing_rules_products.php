<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Le club ouvre 4 terrains de foot à 5 en salle à côté des 6 terrains de padel.
    // Les lignes déjà présentes restent du padel : leurs réservations et leurs factures ne changent pas.
    public function up(): void
    {
        Schema::table('courts', fn (Blueprint $table) => $table->string('sport', 10)->default('padel'));
        Schema::table('pricing_rules', fn (Blueprint $table) => $table->string('sport', 10)->default('padel'));
        // Un article sans sport (null) est proposé sur tous les terrains.
        Schema::table('products', fn (Blueprint $table) => $table->string('sport', 10)->nullable());
        DB::table('products')->update(['sport' => 'padel']);
        foreach (['courts', 'pricing_rules', 'products'] as $table) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_sport_check CHECK (sport IN ('padel', 'foot5'))");
        }
    }

    public function down(): void
    {
        foreach (['courts', 'pricing_rules', 'products'] as $table) {
            DB::statement("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_sport_check");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('sport'));
        }
    }
};
