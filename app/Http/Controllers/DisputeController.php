<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Dispute;
use App\Services\Clock;
use App\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** F17 : le joueur introduit une demande pour un imprévu grave et suit son état. */
class DisputeController extends Controller
{
    public function __construct(private DisputeService $disputes) {}

    public function index(Request $request): Response
    {
        return Inertia::render('disputes/index', [
            'disputes' => Dispute::with('booking.court')->where('user_id', $request->user()->id)->latest('id')->get()
                ->map(fn (Dispute $d) => [
                    'id' => $d->id,
                    'court' => $d->booking->court->name,
                    'starts_at' => Clock::local($d->booking->starts_at)->format('d/m/Y H:i'),
                    'status' => $d->status,
                    'decision_note' => $d->decision_note,
                    'refunded_cents' => $d->refunded_cents,
                    'created_at' => Clock::local($d->created_at->toImmutable())->format('d/m/Y'),
                ]),
        ]);
    }

    public function create(Request $request, Booking $booking): Response
    {
        // Seul un joueur qui a payé sur cette réservation la voit : les autres reçoivent 404, comme pour la réservation.
        abort_if($this->disputes->payments($booking, $request->user())->isEmpty(), 404);
        $booking->load('court');

        return Inertia::render('disputes/create', [
            'booking' => [
                'id' => $booking->getRouteKey(),
                'court' => $booking->court->name,
                'starts_at' => Clock::local($booking->starts_at)->format('d/m/Y H:i'),
            ],
        ]);
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_if($this->disputes->payments($booking, $request->user())->isEmpty(), 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);
        try {
            $this->disputes->open($request->user(), $booking, $data['reason'], $request->file('attachment'));
        } catch (BookingException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return to_route('disputes.index')->with('success', __('Votre demande est reçue. Le club vous répond par e-mail.'));
    }
}
