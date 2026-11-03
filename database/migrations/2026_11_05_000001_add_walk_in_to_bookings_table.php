<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Réservation au comptoir : le client n'a pas de compte, le gérant qui l'encode reste l'organisateur technique.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('walk_in_name', 120)->nullable();
            $table->string('walk_in_contact', 190)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['walk_in_name', 'walk_in_contact']);
        });
    }
};
