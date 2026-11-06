<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;
use Spatie\Csp\Nonce\NonceGenerator;

/** Un seul nonce par requête, partagé entre l'en-tête CSP et les balises écrites par Vite. */
class ViteCspNonce implements NonceGenerator
{
    public function generate(): string
    {
        return Vite::useCspNonce();
    }
}
