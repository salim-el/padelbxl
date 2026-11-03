<?php

// Vendeur repris sur les factures électroniques (UBL). Ce sont les données du club : elles viennent du .env, jamais du code.
return [
    'seller' => [
        'name' => env('CLUB_LEGAL_NAME'),
        'vat' => env('CLUB_VAT_NUMBER'),
        'street' => env('CLUB_STREET'),
        'postcode' => env('CLUB_POSTCODE'),
        'city' => env('CLUB_CITY'),
    ],
];
