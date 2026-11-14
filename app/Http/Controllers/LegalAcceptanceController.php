<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use App\Services\LegalConsents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** F7 : page qui présente la nouvelle version des documents et enregistre l'accord du joueur, avec la date. */
class LegalAcceptanceController extends Controller
{
    public function __construct(private LegalConsents $consents) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $missing = $this->consents->missing($request->user());
        if ($missing->isEmpty()) {
            return redirect()->intended(route('planning'));
        }

        return Inertia::render('legal/accept', [
            'documents' => $missing->map(fn (LegalDocument $d) => [
                'type' => $d->type,
                'version' => $d->version,
                'content' => $d->content,
                'published_at' => $d->published_at->toDateString(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['accept' => ['accepted']]);
        $this->consents->accept($request->user(), $request->ip());

        return redirect()->intended(route('planning'));
    }
}
