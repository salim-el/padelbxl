<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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

        // Politique d'accès (T07) : le droit de gérer le club est défini ici, une seule fois.
        Gate::define('manage-club', fn (User $user) => $user->isStaff());
    }
}
