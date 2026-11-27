<?php

namespace Database\Seeders;

use App\Models\Consent;
use App\Models\Court;
use App\Models\LegalDocument;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    // Mot de passe de démonstration, identique au dump du T14. Il est public : en ligne, DEMO_PASSWORD le remplace.
    public const DEMO_PASSWORD = 'Padeltfeicc2026#';

    /** Tarifs du foot à 5, hypothèses du club. La migration 2026_11_26_000002 les reprend pour une base déjà en service. */
    public const FOOT_RULES = [
        ['name' => 'Heures creuses', 'sport' => 'foot5', 'start_time' => '09:00', 'end_time' => '17:00', 'price_cents' => 7000, 'vat_rate' => 6, 'valid_from' => '2026-01-01'],
        ['name' => 'Heures pleines', 'sport' => 'foot5', 'start_time' => '17:00', 'end_time' => '23:00', 'price_cents' => 9000, 'vat_rate' => 6, 'valid_from' => '2026-01-01'],
    ];

    /** Conditions générales 2026.2, qui ajoutent le foot à 5. La migration 2026_11_26_000003 les publie sur une base déjà en service. */
    public const TERMS_2026_2 = [
        'fr' => 'Conditions générales d’ArenaBxl, version 2026.2. Elles ajoutent le foot à 5. Texte complet : /conditions-generales.',
        'nl' => 'Algemene voorwaarden van ArenaBxl, versie 2026.2. Ze voegen minivoetbal toe. Volledige tekst: /conditions-generales.',
        'en' => 'ArenaBxl terms and conditions, version 2026.2. They add 5-a-side football. Full text: /conditions-generales.',
    ];

    // Date du texte, pour une base neuve. En UTC, sans zone, comme Laravel écrit les dates. Une base en service reçoit la version au déploiement.
    public const TERMS_2026_2_PUBLISHED_AT = '2026-11-26 00:00:00';

    public function run(): void
    {
        foreach (range(1, 6) as $i) {
            Court::create(['name' => "Terrain $i", 'sport' => 'padel']);
        }
        // Le foot après le padel : les terrains 1 à 6 gardent leurs identifiants, comme dans la base en ligne.
        foreach (range(1, 4) as $i) {
            Court::create(['name' => "Foot $i", 'sport' => 'foot5']);
        }
        // TVA de 6 % : accès aux installations sportives.
        $rules = [
            ['Heures creuses', '09:00', '17:00', 3360],
            ['Heures pleines', '17:00', '23:00', 4800],
        ];
        foreach ($rules as [$name, $start, $end, $price]) {
            PricingRule::create(['name' => $name, 'sport' => 'padel', 'start_time' => $start, 'end_time' => $end, 'price_cents' => $price, 'vat_rate' => 6, 'valid_from' => '2026-01-01']);
        }
        foreach (self::FOOT_RULES as $rule) {
            PricingRule::create($rule);
        }
        // Matériel : TVA de 21 %, contrairement à l'accès au terrain.
        Product::create(['name' => 'Location de raquette', 'sport' => 'padel', 'kind' => 'rental', 'price_cents' => 400, 'vat_rate' => 21, 'stock' => 12]);
        Product::create(['name' => 'Tube de 3 balles neuves', 'sport' => 'padel', 'kind' => 'sale', 'price_cents' => 600, 'vat_rate' => 21, 'stock' => 40]);
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
        User::all()->each(fn (User $u) => self::acceptLegalDocuments($u));
    }

    /**
     * Compte de démonstration : chaque version publiée est acceptée, dans sa langue, le jour de sa publication ou à l'inscription.
     * La version en vigueur est ainsi acceptée quel que soit le jour où la démo ou un test place l'horloge.
     */
    public static function acceptLegalDocuments(User $user): void
    {
        foreach (LegalDocument::where('locale', $user->locale ?? 'fr')->get() as $doc) {
            Consent::firstOrCreate(['user_id' => $user->id, 'legal_document_id' => $doc->id], ['accepted_at' => $doc->published_at->max(now())]);
        }
    }

    /** Versions 2026.1 des conditions générales et de la politique de vie privée, puis 2026.2 des conditions. Le texte complet est la page publique. */
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
        foreach (self::TERMS_2026_2 as $locale => $content) {
            LegalDocument::firstOrCreate(['type' => 'terms', 'version' => '2026.2', 'locale' => $locale], ['content' => $content, 'published_at' => self::TERMS_2026_2_PUBLISHED_AT]);
        }
    }
}
