<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\SuspensionAppeal;
use App\Services\AccountStatusService;
use App\Services\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** F29 : contestations des suspensions, examinées par une autre personne que celle qui a suspendu. */
class AdminAppealController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage-club'), 403);

        return Inertia::render('admin/appeals', [
            'appeals' => SuspensionAppeal::with(['user', 'suspension.changer'])
                ->orderByRaw("CASE WHEN status = 'received' THEN 0 ELSE 1 END")->latest('id')->limit(100)->get()
                ->map(fn (SuspensionAppeal $a) => [
                    'id' => $a->id,
                    'player' => $a->user->name,
                    'reason' => $a->suspension->reason,
                    'suspended_by' => $a->suspension->changer?->name,
                    'can_decide' => $a->status === 'received' && $a->suspension->changed_by !== $request->user()->id,
                    'message' => $a->message,
                    'status' => $a->status,
                    'decision_note' => $a->decision_note,
                    'created_at' => Clock::local($a->created_at)->format('d/m/Y H:i'),
                ]),
        ]);
    }

    public function decide(Request $request, SuspensionAppeal $appeal, AccountStatusService $accounts): RedirectResponse
    {
        abort_unless($request->user()->can('manage-club'), 403);
        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'decision_note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        try {
            $accounts->decideAppeal($appeal, $request->user(), (bool) $data['accepted'], $data['decision_note']);
        } catch (BookingException $e) {
            return back()->withErrors(['appeal' => $e->getMessage()]);
        }

        return back()->with('success', 'Décision enregistrée. Le joueur est prévenu par e-mail.');
    }
}
