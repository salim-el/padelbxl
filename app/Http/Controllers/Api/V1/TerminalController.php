<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalController extends Controller
{
    /** Pour le contrôleur LED du terrain : la partie en cours et la suivante, sans nom de joueur. */
    public function current(Court $court): JsonResponse
    {
        $playing = Booking::where('court_id', $court->id)->whereIn('status', ['confirmed', 'used']);
        $current = (clone $playing)->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
        $next = (clone $playing)->where('starts_at', '>', now())->orderBy('starts_at')->first();

        return response()->json([
            'court' => ['id' => $court->id, 'name' => $court->name, 'is_arena' => $court->is_arena],
            'current' => $this->slot($current),
            'next' => $this->slot($next),
        ]);
    }

    /** Pour le lecteur QR de l'entrée : même contrôle et même journal que la borne du site. */
    public function access(Request $request, AccessService $access): JsonResponse
    {
        $data = $request->validate(['court_id' => ['required', 'integer', 'exists:courts,id'], 'token' => ['required', 'string', 'max:128']]);

        return response()->json($access->validate($data['token'], Court::findOrFail($data['court_id'])));
    }

    private function slot(?Booking $b): ?array
    {
        return $b === null ? null : [
            'starts_at' => $b->starts_at->toIso8601String(),
            'ends_at' => $b->ends_at->toIso8601String(),
            'is_arena' => $b->is_arena,
            'game_mode' => $b->game_mode,
        ];
    }
}
