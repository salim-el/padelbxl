<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Clock;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenDataController extends Controller
{
    private const CLUB = 'Arena Padel Club';

    // Identifiant du padel dans la liste d'activités OpenActive.
    private const PADEL = 'https://openactive.io/activity-list#178ac9d5-9042-41e4-b8b9-12edf824afbf';

    /** Créneaux libres d'un jour au format OpenActive (JSON-LD), sous licence CC BY 4.0, sans aucune donnée personnelle. */
    public function slots(Request $request, SlotService $slots): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:+30 days']])['date']
            ?? Clock::local(CarbonImmutable::now())->toDateString();
        $day = $slots->forDate($date);
        $arcade = $day['arcade_supplement_cents'];

        $items = collect($day['courts'])->map(fn (array $court, int $i) => [
            '@type' => 'FacilityUse',
            '@id' => $request->url().'#terrain-'.$court['id'],
            'name' => $court['name'],
            'activity' => [['@type' => 'Concept', '@id' => self::PADEL, 'prefLabel' => 'Padel Tennis', 'inScheme' => 'https://openactive.io/activity-list']],
            'provider' => ['@type' => 'Organization', 'name' => self::CLUB],
            'location' => ['@type' => 'Place', 'name' => self::CLUB, 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Anderlecht', 'postalCode' => '1070', 'addressCountry' => 'BE']],
            'event' => collect($day['rows'])
                ->filter(fn (array $row) => $row['cells'][$i]['available'])
                ->map(function (array $row) use ($i, $arcade) {
                    $price = $row['cells'][$i]['price_cents'];
                    $offers = [['@type' => 'Offer', 'name' => 'Partie classique', 'price' => $price / 100, 'priceCurrency' => 'EUR']];
                    if ($arcade !== null) {
                        $offers[] = ['@type' => 'Offer', 'name' => 'Partie Arcade', 'price' => ($price + $arcade) / 100, 'priceCurrency' => 'EUR'];
                    }

                    return [
                        '@type' => 'Slot',
                        'startDate' => $row['starts_at'],
                        'endDate' => CarbonImmutable::parse($row['starts_at'])->addMinutes(Clock::SLOT_MINUTES)->toIso8601String(),
                        'duration' => 'PT1H30M',
                        'remainingUses' => 1,
                        'maximumUses' => 1,
                        'offers' => $offers,
                    ];
                })->values()->all(),
        ])->all();

        return response()->json([
            '@context' => ['https://openactive.io/', 'https://schema.org/'],
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'publisher' => self::CLUB,
            'generated' => now()->toIso8601String(),
            'date' => $date,
            'items' => $items,
        ], 200, ['Content-Type' => 'application/ld+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
