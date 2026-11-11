<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Services\BookingService;
use App\Services\Clock;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Réservation au comptoir : un client sans compte réserve et paie sur place, le gérant encode. */
class CounterController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function create(Request $request): Response
    {
        $this->ensureStaff($request);

        return Inertia::render('admin/counter', [
            'courts' => Court::where('is_active', true)->orderBy('id')->get(['id', 'name'])->all(),
            'slots' => Clock::SLOTS,
            'today' => now(Clock::ZONE)->format('Y-m-d'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureStaff($request);
        $data = $request->validate([
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'in:'.implode(',', Clock::SLOTS)],
            'name' => ['required', 'string', 'max:120'],
            // Un e-mail ou un numéro de téléphone, pour prévenir le client en cas de fermeture du terrain.
            'contact' => ['required', 'string', 'max:190', 'regex:/^([^@\s]+@[^@\s]+\.[^@\s]+|\+?[0-9 .\/-]{8,20})$/'],
            'method' => ['required', 'in:cash,terminal'],
        ], ['contact.regex' => 'Indiquez un e-mail ou un numéro de téléphone.']);
        try {
            $booking = $this->bookings->counter(
                $request->user(), Court::findOrFail($data['court_id']), Clock::slotStart($data['date'], $data['time']),
                $data['name'], $data['contact'], $data['method'],
            );
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }
        activity('staff')->performedOn($booking)->event('counter-booking-created')
            ->withProperties(['method' => $data['method']])->log('Réservation au comptoir');

        return to_route('admin.counter.show', $booking)->with('success', 'Réservation payée et confirmée. Imprimez le ticket pour le client.');
    }

    public function show(Request $request, Booking $booking): Response
    {
        $this->ensureCounterBooking($request, $booking);
        $booking->load('court', 'players.accessPass', 'invoices.creditNote');
        $player = $booking->players->firstWhere('is_organizer', true);
        $pass = $player?->accessPass;

        return Inertia::render('admin/counter-ticket', [
            'booking' => [
                'id' => $booking->public_id,
                'court' => $booking->court->name,
                'date' => Clock::local($booking->starts_at)->format('d/m/Y'),
                'time' => Clock::local($booking->starts_at)->format('H:i').' – '.Clock::local($booking->ends_at)->format('H:i'),
                'name' => $booking->walk_in_name,
                'contact' => $booking->walk_in_contact,
                'status' => $booking->status,
                'price_cents' => $booking->price_cents,
                'invoice' => $booking->invoices->first()?->number,
                'credit_note' => $booking->invoices->first()?->creditNote?->number,
                'cancellable' => $booking->status === 'confirmed' && $booking->starts_at->isFuture(),
                'refundable' => now()->lte($booking->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS)),
            ],
            'qr' => $pass && $pass->revoked_at === null && $booking->status === 'confirmed' ? (new QRCode)->render(BookingService::passToken($player)) : null,
            'qr_window' => $pass ? Clock::local($pass->valid_from)->format('H:i').' – '.Clock::local($pass->valid_until)->format('H:i') : null,
        ]);
    }

    /** Même règle que pour un joueur : remboursé (en espèces au comptoir) si l'annulation arrive au moins 24 h avant. */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $this->ensureCounterBooking($request, $booking);
        try {
            $booking = $this->bookings->cancel($booking, $request->user());
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }
        activity('staff')->performedOn($booking)->event('counter-booking-cancelled')->log('Réservation au comptoir annulée');

        return back()->with('success', $booking->status === 'cancelled_refunded'
            ? 'Annulée. Rendez le montant au client : une note de crédit a été émise.'
            : 'Annulée moins de 24 h avant : pas de remboursement.');
    }

    private function ensureStaff(Request $request): void
    {
        abort_unless($request->user()->can('manage-club'), 403);
    }

    private function ensureCounterBooking(Request $request, Booking $booking): void
    {
        $this->ensureStaff($request);
        abort_if($booking->walk_in_name === null, 404);
    }
}
