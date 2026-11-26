<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F17 et F27 : demande pour un imprévu grave, et la décision motivée du gérant.
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('appeal_of_id')->nullable()->constrained('disputes');
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status', 20)->default('received');
            $table->text('decision_note')->nullable();
            $table->integer('refunded_cents')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN ('received', 'in_review', 'accepted_full', 'accepted_partial', 'accepted_credit', 'rejected'))");
        // Une décision est toujours motivée et signée.
        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_decision_check CHECK (status IN ('received', 'in_review') OR (decision_note IS NOT NULL AND decided_by IS NOT NULL AND decided_at IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX disputes_one_per_booking ON disputes (booking_id, user_id) WHERE appeal_of_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX disputes_one_appeal ON disputes (appeal_of_id) WHERE appeal_of_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
