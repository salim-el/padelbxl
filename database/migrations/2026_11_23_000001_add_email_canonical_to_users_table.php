<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Une même boîte aux lettres ne doit ouvrir qu'un compte : la forme canonique de l'adresse est unique (User::canonicalEmail).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_canonical')->nullable()->after('email');
        });
        DB::table('users')->orderBy('id')->each(function (object $user) {
            DB::table('users')->where('id', $user->id)->update(['email_canonical' => User::canonicalEmail($user->email)]);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_canonical')->nullable(false)->change();
            $table->unique('email_canonical');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_canonical'));
    }
};
