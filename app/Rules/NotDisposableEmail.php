<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** F1 : refuse une adresse d'un service d'e-mails jetables (config/disposable-email.php), sous-domaines compris. */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = mb_strtolower((string) substr(strrchr((string) $value, '@') ?: '', 1));
        foreach (config('disposable-email.domains') as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                $fail(__('Les adresses e-mail jetables ne sont pas acceptées.'));

                return;
            }
        }
    }
}
