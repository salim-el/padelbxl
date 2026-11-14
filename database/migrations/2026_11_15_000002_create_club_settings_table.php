<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F24 : heures d'ouverture et durée d'un créneau, réglées par le gérant. Une seule ligne.
        Schema::create('club_settings', function (Blueprint $table) {
            $table->id();
            $table->time('opens_at');
            $table->time('closes_at');
            $table->smallInteger('slot_minutes');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE club_settings ADD CONSTRAINT club_settings_hours_check CHECK (closes_at > opens_at)');
        DB::statement('ALTER TABLE club_settings ADD CONSTRAINT club_settings_slot_check CHECK (slot_minutes IN (60, 90, 120))');
        DB::table('club_settings')->insert(['opens_at' => '09:00', 'closes_at' => '23:00', 'slot_minutes' => 90, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('club_settings');
    }
};
