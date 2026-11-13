<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_terms_and_privacy_are_readable_without_an_account(): void
    {
        config(['invoicing.seller' => ['name' => 'Club de test SRL', 'vat' => 'BE0999999999', 'street' => 'Rue du Test 1', 'postcode' => '1070', 'city' => 'Anderlecht']]);

        $this->get('/conditions-generales')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('legal/terms')
            ->where('club.name', 'Club de test SRL'));
        $this->get('/confidentialite')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('legal/privacy')
            ->where('club.vat', 'BE0999999999'));
    }
}
