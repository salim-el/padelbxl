<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_only_run_scripts_carrying_the_request_nonce(): void
    {
        $response = $this->get('/')->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $nonce);
        $response->assertSee('nonce="'.$nonce[1].'"', false);

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
    }

    public function test_web_and_api_responses_carry_the_security_headers(): void
    {
        foreach (['/', '/api/v1/opendata/slots.jsonld'] as $url) {
            $this->get($url)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeaderMissing('Strict-Transport-Security');
        }

        $this->get('/api/v1/opendata/slots.jsonld')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
