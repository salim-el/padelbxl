<?php

namespace Database\Seeders;

use App\Models\Court;
use App\Models\LegalDocument;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Services\LegalConsents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    // Mot de passe de démonstration, identique au dump du T14. Il est public : en ligne, DEMO_PASSWORD le remplace.
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
        $password = Hash::make(config('app.demo_password') ?: self::DEMO_PASSWORD);
        User::create(['name' => 'Lucas Martin', 'email' => 'lucas.martin@example.test', 'password' => $password, 'email_verified_at' => now(), 'role' => 'player']);
        foreach ([['Inès Laurent', 'ines.laurent@example.test', now(), 'active'],
            ['Thomas Peeters', 'thomas.peeters@example.test', null, 'active'],
            ['Yassin Benali', 'yassin.benali@example.test', now(), 'suspended']] as [$name, $email, $verified, $status]) {
            User::create(['name' => $name, 'email' => $email, 'password' => $password, 'email_verified_at' => $verified, 'role' => 'player', 'status' => $status]);
        }
        User::create(['name' => 'Marc Dumont', 'email' => 'marc.dumont@example.test', 'password' => $password, 'email_verified_at' => now(), 'role' => 'manager']);
        // F35 : l'administrateur gère le personnel ; il n'utilise pas l'espace gérant au quotidien.
        User::create(['name' => 'Claire Vermeulen', 'email' => 'claire.vermeulen@example.test', 'password' => $password, 'email_verified_at' => now(), 'role' => 'admin']);
        self::legalDocuments();
        User::all()->each(fn (User $u) => app(LegalConsents::class)->accept($u, null));
    }

    /** Version 2026.1 des conditions générales et de la politique de vie privée. Le texte complet est la page publique. */
    public static function legalDocuments(): void
    {
        $texts = [
            'terms' => ['fr' => 'Conditions générales d’ArenaBxl, version 2026.1. Texte complet : /conditions-generales.', 'nl' => 'Algemene voorwaarden van ArenaBxl, versie 2026.1. Volledige tekst: /conditions-generales.', 'en' => 'ArenaBxl terms and conditions, version 2026.1. Full text: /conditions-generales.'],
            'privacy' => ['fr' => 'Politique de vie privée d’ArenaBxl, version 2026.1. Texte complet : /confidentialite.', 'nl' => 'Privacybeleid van ArenaBxl, versie 2026.1. Volledige tekst: /confidentialite.', 'en' => 'ArenaBxl privacy policy, version 2026.1. Full text: /confidentialite.'],
        ];
        foreach ($texts as $type => $byLocale) {
            foreach ($byLocale as $locale => $content) {
                LegalDocument::firstOrCreate(['type' => $type, 'version' => '2026.1', 'locale' => $locale], ['content' => $content, 'published_at' => '2026-01-01 00:00:00+01']);
            }
        }
    }
}
