<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Le club ne propose plus que des terrains classiques : le mode Arcade et ses tarifs disparaissent.
    // Les réservations déjà payées gardent leur prix et leurs factures.
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_game_mode_check');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['game_mode', 'is_arena']);
        });
        DB::table('pricing_rules')->where('is_arena', true)->delete();
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('is_arena');
        });
        Schema::table('courts', function (Blueprint $table) {
            $table->dropColumn('is_arena');
        });
    }

    // Les colonnes reviennent vides : les tarifs Arena supprimés ne sont pas recréés.
    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->boolean('is_arena')->default(false);
        });
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->boolean('is_arena')->default(false);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_arena')->default(false);
            $table->string('game_mode', 20)->nullable();
        });
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_game_mode_check CHECK (is_arena = (game_mode IS NOT NULL) AND (game_mode IS NULL OR game_mode = 'arcade'))");
    }
};
