<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\Clock;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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
        ]);
        $date = $data['date'] ?? $today;
        // F14 : planning ouvert pour déplacer une réservation de l'organisateur connecté ; sinon, planning normal.
        $moving = isset($data['move']) && $request->user()
            ? Booking::with('court')->where('public_id', $data['move'])->where('organizer_id', $request->user()->id)->where('status', 'confirmed')->first()
            : null;

        return Inertia::render('planning', ['date' => $date, 'today' => $today, 'max' => $max, 'moving' => $moving ? [
            'id' => $moving->public_id,
            'court' => $moving->court->name,
            'when' => Clock::local($moving->starts_at)->locale(app()->getLocale())->isoFormat(__('dddd D MMMM YYYY')).' · '.Clock::local($moving->starts_at)->format('H:i'),
        ] : null] + $slots->forDate($date));
    }
}
