<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F15 : avoir utilisable sur une prochaine réservation, pendant un an.
        Schema::create('store_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->integer('amount_cents');
            $table->integer('remaining_cents');
            $table->string('origin', 10);
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('user_id');
            $table->index('refund_id');
        });
        DB::statement('ALTER TABLE store_credits ADD CONSTRAINT store_credits_amount_check CHECK (amount_cents > 0)');
        DB::statement('ALTER TABLE store_credits ADD CONSTRAINT store_credits_remaining_check CHECK (remaining_cents BETWEEN 0 AND amount_cents)');
        DB::statement("ALTER TABLE store_credits ADD CONSTRAINT store_credits_origin_check CHECK (origin IN ('refund', 'dispute', 'goodwill'))");

        // Chaque utilisation d'un avoir lors d'un paiement.
        Schema::create('store_credit_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_credit_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->integer('amount_cents');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('store_credit_id');
            $table->index('payment_id');
        });
        DB::statement('ALTER TABLE store_credit_usages ADD CONSTRAINT store_credit_usages_amount_check CHECK (amount_cents > 0)');

        // Un remboursement revient au moyen de paiement d'origine ou en avoir.
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('method', 20)->default('original_payment');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_method_check CHECK (method IN ('original_payment', 'store_credit'))");
    }

    public function down(): void
    {
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn('method'));
        Schema::dropIfExists('store_credit_usages');
        Schema::dropIfExists('store_credits');
    }
};
