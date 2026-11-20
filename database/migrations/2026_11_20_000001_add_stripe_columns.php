<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F9 : Stripe en mode test. Client et carte de garantie de l'organisateur, débit de garantie, référence du remboursement.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable();
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('guarantee_payment_method_id')->nullable();
            $table->timestampTz('guarantee_accepted_at')->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('is_guarantee_charge')->default(false);
        });
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('stripe_refund_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn('stripe_refund_id'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('is_guarantee_charge'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['guarantee_payment_method_id', 'guarantee_accepted_at']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('stripe_customer_id'));
    }
};
