<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Court;
use App\Models\PricingRule;
use App\Services\Clock;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanningController extends Controller
{
    public function __invoke(Request $request, SlotService $slots): Response
    {
        $today = CarbonImmutable::now(Clock::ZONE)->toDateString();
        $max = CarbonImmutable::now(Clock::ZONE)->addDays(30)->toDateString();
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.$max],
            'move' => ['nullable', 'uuid'],
            'sport' => ['nullable', Rule::in(Court::SPORTS)],
        ]);
        $date = $data['date'] ?? $today;
        // F14 : planning ouvert pour déplacer une réservation de l'organisateur connecté ; sinon, planning normal.
        $moving = isset($data['move']) && $request->user()
            ? Booking::with('court')->where('public_id', $data['move'])->where('organizer_id', $request->user()->id)->where('status', 'confirmed')->first()
            : null;
        // Onglets du planning : les sports qui ont au moins un terrain actif, dans l'ordre du club.
        $sports = array_values(array_intersect(Court::SPORTS, Court::where('is_active', true)->distinct()->pluck('sport')->all()));
        // Une réservation ne change pas de sport : pendant un déplacement, le planning montre les terrains du sien.
        // Sinon, le premier sport actif : un club sans padel actif ouvre le foot, pas un planning vide.
        $sport = $moving?->court->sport ?? $data['sport'] ?? $sports[0] ?? 'padel';

        return Inertia::render('planning', ['date' => $date, 'today' => $today, 'max' => $max,
            'sports' => $sports,
            // Sans sport dans l'adresse ni déplacement en cours, le joueur choisit d'abord ce qu'il réserve.
            'sport_chosen' => $moving !== null || isset($data['sport']) || count($sports) < 2,
            // Onglets et écran de choix : durée du créneau, joueurs et plus petit tarif en vigueur de chaque sport.
            'minutes' => collect(Court::SPORTS)->mapWithKeys(fn (string $s) => [$s => Clock::slotMinutes($s)]),
            'players' => Court::PLAYERS,
            'from_prices' => collect(Court::SPORTS)->mapWithKeys(fn (string $s) => [$s => PricingRule::where('sport', $s)
                ->whereDate('valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today))
                ->min('price_cents')]),
            'moving' => $moving ? [
                'id' => $moving->public_id,
                'court' => $moving->court->name,
                'when' => Clock::local($moving->starts_at)->locale(app()->getLocale())->isoFormat(__('dddd D MMMM YYYY')).' · '.Clock::local($moving->starts_at)->format('H:i'),
            ] : null] + $slots->forDate($date, $sport));
    }
}
