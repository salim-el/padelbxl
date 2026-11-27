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

        // Deux tarifs par sport, dans l'ordre des heures ; padel et foot commencent aux mêmes heures, leur ordre entre eux n'est pas fixé.
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('prices', 4)
            ->where('prices', fn ($prices) => $prices->where('sport', 'padel')->pluck('price_cents')->values()->all() === [3360, 4800]
                && $prices->where('sport', 'foot5')->pluck('price_cents')->values()->all() === [7000, 9000]));
    }
}
