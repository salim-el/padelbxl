<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    // Seul mot de passe « fuité » connu des tests (SHA-1 56DA5D0CB2C16123D6F351BE4E3984A83BFD3ECE).
    public const LEAKED_PASSWORD = 'mot-de-passe-fuite-2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Les tests ne joignent jamais Have I Been Pwned : la fausse réponse ne contient que l'empreinte de LEAKED_PASSWORD.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response("D0CB2C16123D6F351BE4E3984A83BFD3ECE:42\r\n")]);
    }
}
