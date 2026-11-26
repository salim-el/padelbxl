<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $statuses = "'pending', 'active', 'suspended', 'revoked', 'deactivated', 'anonymized'";

        // F28 : chaque changement d'état d'un compte, avec son motif et qui l'a décidé.
        Schema::create('account_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->timestampTz('until')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE account_status_histories ADD CONSTRAINT ash_to_check CHECK (to_status IN ($statuses))");
        // Une suspension ou une révocation est toujours motivée.
        DB::statement("ALTER TABLE account_status_histories ADD CONSTRAINT ash_reason_check CHECK (to_status NOT IN ('suspended', 'revoked') OR reason IS NOT NULL)");

        // F28 : après une révocation pour fraude, l'empreinte de l'e-mail empêche de recréer un compte. L'adresse n'est pas gardée.
        Schema::create('blocked_identities', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64)->nullable()->unique();
            $table->string('card_fingerprint', 64)->nullable();
            $table->text('reason');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE blocked_identities ADD CONSTRAINT blocked_identities_check CHECK (email_hash IS NOT NULL OR card_fingerprint IS NOT NULL)');

        // F29 : contestation d'une suspension, examinée par un autre membre du personnel que celui qui a suspendu.
        Schema::create('suspension_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_status_history_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->string('status', 10)->default('received');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE suspension_appeals ADD CONSTRAINT suspension_appeals_status_check CHECK (status IN ('received', 'accepted', 'rejected'))");
        DB::statement("ALTER TABLE suspension_appeals ADD CONSTRAINT suspension_appeals_decision_check CHECK (status = 'received' OR (decision_note IS NOT NULL AND decided_by IS NOT NULL AND decided_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('suspension_appeals');
        Schema::dropIfExists('blocked_identities');
        Schema::dropIfExists('account_status_histories');
    }
};
