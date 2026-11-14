<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Dispute;
use App\Services\Clock;
use App\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** F27 : le gérant consulte les demandes, les examine et décide, avec une motivation. */
class AdminDisputeController extends Controller
{
    public function __construct(private DisputeService $disputes) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage-club'), 403);

        return Inertia::render('admin/disputes', [
            'disputes' => Dispute::with(['booking.court', 'user'])
                ->orderByRaw("CASE WHEN status IN ('received', 'in_review') THEN 0 ELSE 1 END")->latest('id')->limit(100)->get()
                ->map(fn (Dispute $d) => $this->present($d)),
        ]);
    }

    public function show(Request $request, Dispute $dispute): Response
    {
        abort_unless($request->user()->can('manage-club'), 403);
        // Ouvrir la demande la met « en examen » : le joueur voit qu'elle est lue.
        $this->disputes->review($dispute);
        $dispute->refresh()->load(['booking.court', 'user', 'decider']);
        $payments = $this->disputes->payments($dispute->booking, $dispute->user);

        return Inertia::render('admin/dispute', [
            'dispute' => $this->present($dispute) + [
                'reason' => $dispute->reason,
                'has_attachment' => $dispute->attachment_path !== null,
                'decided_by' => $dispute->decider?->name,
                'refundable_cents' => (int) $payments->sum(fn ($p) => $p->amount_cents - $p->refunds()->where('status', '<>', 'failed')->sum('amount_cents')),
            ],
        ]);
    }

    public function decide(Request $request, Dispute $dispute): RedirectResponse
    {
        abort_unless($request->user()->can('manage-club'), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(Dispute::DECISIONS)],
            'decision_note' => ['required', 'string', 'min:10', 'max:2000'],
            'amount' => ['exclude_unless:decision,accepted_partial', 'required', 'numeric', 'min:0.01'],
        ]);
        try {
            $this->disputes->decide($dispute, $request->user(), $data['decision'], $data['decision_note'],
                isset($data['amount']) ? (int) round($data['amount'] * 100) : null);
        } catch (BookingException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return to_route('admin.disputes.index')->with('success', __('Décision enregistrée. Le joueur est prévenu par e-mail.'));
    }

    /** La pièce justificative reste privée : seul le personnel la télécharge. */
    public function attachment(Request $request, Dispute $dispute): StreamedResponse
    {
        abort_unless($request->user()->can('manage-club'), 403);
        abort_if($dispute->attachment_path === null, 404);

        return Storage::disk('local')->download($dispute->attachment_path, 'piece-'.$dispute->id.'.'.pathinfo($dispute->attachment_path, PATHINFO_EXTENSION));
    }

    private function present(Dispute $d): array
    {
        return [
            'id' => $d->id,
            'player' => $d->user->name,
            'court' => $d->booking->court->name,
            'starts_at' => Clock::local($d->booking->starts_at)->format('d/m/Y H:i'),
            'status' => $d->status,
            'decision_note' => $d->decision_note,
            'refunded_cents' => $d->refunded_cents,
            'created_at' => Clock::local($d->created_at->toImmutable())->format('d/m/Y H:i'),
        ];
    }
}
