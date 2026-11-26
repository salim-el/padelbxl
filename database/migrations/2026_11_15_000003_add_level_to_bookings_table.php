<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F11 : niveau demandé dans l'appel à joueurs, sur l'échelle 1 à 7 du profil.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('level_min')->nullable();
            $table->unsignedTinyInteger('level_max')->nullable();
        });
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_level_check CHECK ((level_min IS NULL AND level_max IS NULL) OR (level_min BETWEEN 1 AND 7 AND level_max BETWEEN level_min AND 7))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_level_check');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['level_min', 'level_max']);
        });
    }
};
