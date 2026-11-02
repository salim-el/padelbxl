<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Court::where('is_active', true)->orderBy('id')->get(['id', 'name', 'is_arena'])]);
    }

    /** Créneaux libres d'un jour, sans aucune donnée personnelle : lisible sans jeton. */
    public function availability(Request $request, SlotService $slots): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:+30 days']]);

        return response()->json(['date' => $data['date'], ...$slots->forDate($data['date'])]);
    }
}
