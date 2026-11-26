<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Une boîte aux lettres, un compte : « nom+x@… » ou, chez Gmail, « n.o.m@… » n'en ouvrent pas un second. Comptes désactivés compris. */
class UniqueMailbox implements ValidationRule
{
    public function __construct(private ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = User::withTrashed()->where('email_canonical', User::canonicalEmail((string) $value))
            ->when($this->ignoreId !== null, fn ($q) => $q->whereKeyNot($this->ignoreId))
            ->exists();
        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
