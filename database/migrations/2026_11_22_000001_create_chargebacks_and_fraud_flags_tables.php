<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F30 : contestation bancaire signalée par Stripe, et signalement d'un compte examiné par une personne (jamais une sanction automatique).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chargebacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('stripe_dispute_id')->unique();
            $table->string('status', 10)->default('open');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
        });
        DB::statement("ALTER TABLE chargebacks ADD CONSTRAINT chargebacks_status_check CHECK (status IN ('open', 'won', 'lost'))");

        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 25);
            $table->text('details')->nullable();
            $table->string('status', 10)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE fraud_flags ADD CONSTRAINT fraud_flags_type_check CHECK (type IN ('chargeback', 'no_show', 'shared_card', 'repeated_cancellations', 'blocked_identity', 'late_payment'))");
        DB::statement("ALTER TABLE fraud_flags ADD CONSTRAINT fraud_flags_status_check CHECK (status IN ('open', 'dismissed', 'actioned'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
        Schema::dropIfExists('chargebacks');
    }
};
