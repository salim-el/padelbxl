<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\AccountStatusHistory;
use App\Services\AccountStatusService;
use App\Services\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F29 : le joueur suspendu ne peut plus se connecter. Il conteste par le lien signé reçu avec le motif :
 * sans la clé du serveur, personne ne fabrique le lien d'une autre suspension.
 */
class SuspensionAppealController extends Controller
{
    public function __construct(private AccountStatusService $accounts) {}

    public function create(Request $request, AccountStatusHistory $suspension): Response
    {
        abort_unless($suspension->to_status === 'suspended', 404);

        return Inertia::render('auth/suspension-appeal', [
            'action' => $request->fullUrl(),
            'reason' => $suspension->reason,
            'until' => $suspension->until ? Clock::local($suspension->until)->format('d/m/Y H:i') : null,
            'already' => $suspension->appeal()->exists(),
        ]);
    }

    public function store(Request $request, AccountStatusHistory $suspension): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:20', 'max:2000']]);
        try {
            $this->accounts->appeal($suspension->user, $suspension, $data['message']);
        } catch (BookingException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return to_route('login')->with('status', __('Votre contestation est reçue. Le club vous répond par e-mail.'));
    }
}
