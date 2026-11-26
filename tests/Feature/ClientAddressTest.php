<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Adresse du joueur derrière le proxy de Render et Cloudflare : les limites par adresse en dépendent. */
class ClientAddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/ip', fn (Request $request) => $request->ip());
    }

    public function test_the_player_address_is_found_behind_cloudflare_and_render(): void
    {
        // Le proxy de Render (réseau privé) appelle l'appli ; il a reçu la requête d'un serveur Cloudflare.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.20, 162.158.1.1'])
            ->assertContent('198.51.100.20');
    }

    public function test_a_forged_address_before_the_real_one_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/_test/ip', ['X-Forwarded-For' => '6.6.6.6, 198.51.100.20, 162.158.1.1'])
            ->assertContent('198.51.100.20');
    }

    public function test_an_unknown_hop_is_not_skipped(): void
    {
        // 6.6.6.6 n'est ni Cloudflare ni le réseau privé : l'adresse qu'il annonce n'est pas crue.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.20, 6.6.6.6'])
            ->assertContent('6.6.6.6');
    }
}
