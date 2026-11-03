<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Cahier des charges : 15 caractères minimum, sans règle de composition imposée.
        // Refus des mots de passe déjà fuités (Have I Been Pwned, k-anonymat : seuls 5 caractères de l'empreinte SHA-1 partent).
        Password::defaults(fn () => Password::min(15)->max(128)->uncompromised());
    }
}
