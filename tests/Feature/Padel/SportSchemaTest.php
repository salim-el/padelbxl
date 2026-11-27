<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\PricingRule;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Foot à 5 : terrains, tarifs et articles portent un sport ; ce que le club avait déjà reste du padel. */
class SportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_club_data_seeded_before_the_foot_becomes_padel(): void
    {
        // La base en ligne date d'avant le foot. Un article n'a pas de sport par défaut (null = tous les terrains) :
        // seule la migration passe en padel les lignes déjà présentes. On la rejoue sur des lignes d'avant le foot.
        $migration = require database_path('migrations/2026_11_26_000001_add_sport_to_courts_pricing_rules_products.php');
        $migration->down();
        DB::table('courts')->insert(['name' => 'Terrain 1']);
        DB::table('pricing_rules')->insert(['name' => 'Heures pleines', 'start_time' => '17:00', 'end_time' => '23:00', 'price_cents' => 4800, 'vat_rate' => 6, 'valid_from' => '2026-01-01']);
        DB::table('products')->insert(['name' => 'Location de raquette', 'kind' => 'rental', 'price_cents' => 400, 'vat_rate' => 21, 'stock' => 12]);
        $migration->up();

        $this->assertSame(['padel'], Court::distinct()->pluck('sport')->all());
        $this->assertSame(['padel'], PricingRule::distinct()->pluck('sport')->all());
        $this->assertSame(['padel'], Product::distinct()->pluck('sport')->all());
    }

    public function test_a_court_created_without_sport_is_a_padel_court_for_four_players(): void
    {
        $court = Court::create(['name' => 'Terrain 7'])->fresh();

        $this->assertSame('padel', $court->sport);
        $this->assertSame(4, $court->maxPlayers());
        $this->assertSame(10, (new Court(['sport' => 'foot5']))->maxPlayers());
    }

    public function test_the_database_refuses_an_unknown_sport(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('courts_sport_check');
        Court::create(['name' => 'Squash 1', 'sport' => 'squash']);
    }

    public function test_an_article_without_sport_stays_null(): void
    {
        // null : l'article est proposé sur tous les terrains, padel et foot.
        $towel = Product::create(['name' => 'Location de serviette', 'kind' => 'rental', 'price_cents' => 200, 'vat_rate' => 21, 'stock' => 20]);

        $this->assertNull($towel->fresh()->sport);
    }
}
