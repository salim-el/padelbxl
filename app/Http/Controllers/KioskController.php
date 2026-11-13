<?php

namespace App\Http\Controllers;

use App\Models\Court;
use App\Services\AccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KioskController extends Controller
{
    public function show(Request $request): Response
    {
        $this->ensureStaff($request);

        return Inertia::render('kiosk', [
            'courts' => Court::where('is_active', true)->orderBy('id')->get(['id', 'name'])->all(),
            'result' => session('kiosk_result'),
        ]);
    }

    public function validateToken(Request $request, AccessService $access): RedirectResponse
    {
        $this->ensureStaff($request);
        $data = $request->validate(['court_id' => ['required', 'integer', 'exists:courts,id'], 'token' => ['required', 'string', 'max:128']]);

        return back()->with('kiosk_result', $access->validate($data['token'], Court::findOrFail($data['court_id'])));
    }

    // Dans l'alpha, la borne est simulée par un écran réservé au personnel.
    private function ensureStaff(Request $request): void
    {
        abort_unless($request->user()->can('manage-club'), 403);
    }
}
