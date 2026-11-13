<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CookieNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** Le bandeau ne fait qu'informer : le serveur ne pose que les cookies nécessaires (session et CSRF). */
    public function test_a_visitor_only_receives_strictly_necessary_cookies(): void
    {
        $this->seed(DatabaseSeeder::class);

        $cookies = collect($this->get('/')->headers->getCookies())->map->getName()->sort()->values()->all();

        $this->assertSame(['XSRF-TOKEN', config('session.cookie')], $cookies);
    }
}
