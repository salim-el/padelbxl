<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Double authentification du personnel (T16) : secret TOTP chiffré par la clé de l'application.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Dernière tranche de 30 s acceptée : un code déjà utilisé ne peut pas être rejoué.
            $table->unsignedBigInteger('two_factor_last_counter')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_counter']);
        });
    }
};
