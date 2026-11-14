<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F1 et F7 : chaque version des conditions générales et de la politique de vie privée, et qui l'a acceptée.
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('version', 10);
            $table->char('locale', 2);
            $table->text('content');
            $table->timestampTz('published_at');
            $table->unique(['type', 'version', 'locale']);
        });
        DB::statement("ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_type_check CHECK (type IN ('terms', 'privacy', 'cookies', 'legal_notice'))");
        DB::statement("ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_locale_check CHECK (locale IN ('fr', 'nl', 'en'))");

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_document_id')->constrained();
            $table->timestampTz('accepted_at')->useCurrent();
            $table->ipAddress('ip_address')->nullable();
            $table->unique(['user_id', 'legal_document_id']);
        });

        // F1 : la règle des 13 ans demande la date de naissance et, en dessous, l'e-mail du parent.
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable();
            $table->string('guardian_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['birth_date', 'guardian_email']));
        Schema::dropIfExists('consents');
        Schema::dropIfExists('legal_documents');
    }
};
