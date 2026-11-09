<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_public_and_shows_current_prices(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('prices', 2)
            ->where('prices.0.price_cents', 3360)
            ->where('prices.1.price_cents', 4800));
    }
}
