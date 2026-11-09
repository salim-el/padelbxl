<?php

namespace Database\Seeders;

use App\Models\Court;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    // Mot de passe de démonstration, identique au dump du T14. À changer avant toute mise en ligne.
    public const DEMO_PASSWORD = 'Padeltfeicc2026#';

    public function run(): void
    {
        foreach (range(1, 6) as $i) {
            Court::create(['name' => "Terrain $i"]);
        }
        // TVA de 6 % : accès aux installations sportives.
        $rules = [
            ['Heures creuses', '09:00', '17:00', 3360],
            ['Heures pleines', '17:00', '23:00', 4800],
        ];
        foreach ($rules as [$name, $start, $end, $price]) {
            PricingRule::create(['name' => $name, 'start_time' => $start, 'end_time' => $end, 'price_cents' => $price, 'vat_rate' => 6, 'valid_from' => '2026-01-01']);
        }
        // Matériel : TVA de 21 %, contrairement à l'accès au terrain.
        Product::create(['name' => 'Location de raquette', 'kind' => 'rental', 'price_cents' => 400, 'vat_rate' => 21, 'stock' => 12]);
        Product::create(['name' => 'Tube de 3 balles neuves', 'kind' => 'sale', 'price_cents' => 600, 'vat_rate' => 21, 'stock' => 40]);
        User::create(['name' => 'Lucas Martin', 'email' => 'lucas.martin@example.test', 'password' => Hash::make(self::DEMO_PASSWORD), 'email_verified_at' => now(), 'role' => 'player']);
        foreach ([['Inès Laurent', 'ines.laurent@example.test', now(), 'active'],
            ['Thomas Peeters', 'thomas.peeters@example.test', null, 'active'],
            ['Yassin Benali', 'yassin.benali@example.test', now(), 'suspended']] as [$name, $email, $verified, $status]) {
            User::create(['name' => $name, 'email' => $email, 'password' => Hash::make(self::DEMO_PASSWORD), 'email_verified_at' => $verified, 'role' => 'player', 'status' => $status]);
        }
        User::create(['name' => 'Marc Dumont', 'email' => 'marc.dumont@example.test', 'password' => Hash::make(self::DEMO_PASSWORD), 'email_verified_at' => now(), 'role' => 'manager']);
    }
}
