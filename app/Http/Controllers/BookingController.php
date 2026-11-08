<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\Court;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\SlotService;
use App\Services\UblInvoice;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $items = Booking::with('court')->where('organizer_id', $user->id)->whereNull('walk_in_name')->orderByDesc('starts_at')->limit(50)->get();
        // Parties où un autre joueur m'a invité, retrouvées aussi par l'adresse si l'invitation précède mon compte.
        $invited = BookingService::invitationsOf(BookingPlayer::with('booking.court', 'booking.organizer'), $user)
            ->whereHas('booking', fn ($q) => $q->where('starts_at', '>', now()->subDays(30)))
            ->get()->sortByDesc('booking.starts_at')->values();

        return Inertia::render('bookings/index', [
            'bookings' => $items->map(fn ($b) => $this->present($b))->all(),
            'invitations' => $invited->map(fn ($p) => [...$this->present($p->booking), 'organizer' => $p->booking->organizer->name, 'share_cents' => $p->share_cents, 'share_status' => $p->status])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['court_id' => ['required', 'integer', 'exists:courts,id'], 'starts_at' => ['required', 'date']]);
        try {
            $booking = $this->bookings->hold($request->user(), Court::findOrFail($data['court_id']), CarbonImmutable::parse($data['starts_at'])->utc());
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return to_route('bookings.checkout', $booking);
    }

    public function checkout(Request $request, Booking $booking): Response|RedirectResponse
    {
        $this->ensureOwner($request, $booking);
        if ($booking->status !== 'pending_payment') {
            return to_route('bookings.show', $booking);
        }

        return Inertia::render('bookings/checkout', [
            'booking' => $this->present($booking->load('court')),
            'products' => Product::where('is_active', true)->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind', 'price_cents'])->all(),
        ]);
    }

    public function pay(Request $request, Booking $booking): RedirectResponse
    {
        $this->ensureOwner($request, $booking);
        $data = $request->validate([
            'accept_terms' => ['accepted'],
            'game_mode' => ['nullable', 'in:classic,'.implode(',', Booking::GAME_MODES)],
            'items' => ['nullable', 'array'], 'items.*' => ['integer', 'min:0', 'max:4'],
            'guests' => ['nullable', 'array', 'max:'.(BookingService::MAX_PLAYERS - 1)], 'guests.*' => ['nullable', 'email:rfc', 'max:255', 'distinct:ignore_case'],
        ]);
        $guests = array_values(array_filter($data['guests'] ?? []));
        // Partager le prix engage l'organisateur à payer les parts restées impayées à 24 h du match.
        if ($guests !== [] && ! $request->boolean('accept_guarantee')) {
            return back()->withErrors(['accept_guarantee' => __('Acceptez la garantie pour partager le prix.')]);
        }
        try {
            $this->bookings->confirmPayment($booking, $request->user(), $data['game_mode'] ?? null, $data['items'] ?? [], 'card', $guests);
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return to_route('bookings.show', $booking)->with('success', $guests === []
            ? __('Réservation confirmée. Votre facture est disponible.')
            : __('Réservation confirmée. Vos partenaires ont reçu leur invitation par e-mail.'));
    }

    /** Page d'un joueur invité : sa part, puis son QR code une fois la part réglée. */
    public function share(Request $request, Booking $booking): Response
    {
        $player = BookingService::invitedPlayer($booking, $request->user());
        abort_if($player === null, 404);
        $booking->load('court', 'organizer');
        $pass = $player->accessPass;
        $qr = $pass && $pass->revoked_at === null && in_array($booking->status, ['confirmed', 'used'], true)
            ? (new QRCode)->render(BookingService::passToken($player))
            : null;

        return Inertia::render('bookings/share', [
            'booking' => $this->present($booking),
            'organizer' => $booking->organizer->name,
            'share_cents' => $player->share_cents,
            'share_status' => $player->status,
            'payable' => $player->status === 'invited' && $booking->status === 'confirmed' && $booking->starts_at->isFuture(),
            'qr' => $qr,
            'qr_window' => $pass ? Clock::local($pass->valid_from)->format('H:i').' – '.Clock::local($pass->valid_until)->format('H:i') : null,
            'invoices' => $booking->invoices()->whereIn('payment_id', Payment::where('booking_player_id', $player->id)->where('payer_id', $request->user()->id)->select('id'))->get()
                ->map(fn ($i) => ['number' => $i->number, 'total_cents' => $i->total_cents, 'vat_cents' => $i->vat_cents])->all(),
        ]);
    }

    public function payShare(Request $request, Booking $booking): RedirectResponse
    {
        abort_if(BookingService::invitedPlayer($booking, $request->user()) === null, 404);
        $request->validate(['accept_terms' => ['accepted']]);
        try {
            $this->bookings->payShare($booking, $request->user());
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return to_route('bookings.share', $booking)->with('success', __('Part réglée. Votre QR code est prêt.'));
    }

    public function show(Request $request, Booking $booking): Response
    {
        $this->ensureOwner($request, $booking);
        // Seules les factures payées par l'organisateur : celles d'un partenaire sont à son nom et ne regardent que lui.
        $booking->load(['court', 'players.accessPass', 'players.user', 'items.product',
            'invoices' => fn ($q) => $q->whereIn('payment_id', Payment::where('payer_id', $request->user()->id)->select('id'))]);
        $player = $booking->players->firstWhere('is_organizer', true);
        $pass = $player?->accessPass;
        $qr = $pass && $pass->revoked_at === null && in_array($booking->status, ['confirmed', 'used'], true)
            ? (new QRCode)->render(BookingService::passToken($player))
            : null;

        return Inertia::render('bookings/show', [
            'booking' => $this->present($booking),
            'qr' => $qr,
            'qr_window' => $pass ? Clock::local($pass->valid_from)->format('H:i').' – '.Clock::local($pass->valid_until)->format('H:i') : null,
            'items' => $booking->items->map(fn ($i) => ['name' => $i->product->name, 'quantity' => $i->quantity, 'total_cents' => $i->quantity * $i->unit_price_cents])->all(),
            // Liste vide quand l'organisateur a tout payé seul : l'écran n'affiche alors pas le partage.
            'players' => $booking->players->count() < 2 ? [] : $booking->players->sortByDesc('is_organizer')->values()->map(fn ($p) => [
                // L'organisateur revoit l'adresse qu'il a tapée, jamais le nom du compte : il n'apprend pas qui est inscrit au club.
                'name' => $p->is_organizer ? $request->user()->name : $p->guest_email, 'is_organizer' => $p->is_organizer, 'share_cents' => $p->share_cents, 'status' => $p->status,
            ])->all(),
            'invoices' => $booking->invoices->map(fn ($i) => [
                'number' => $i->number, 'total_cents' => $i->total_cents, 'vat_cents' => $i->vat_cents,
                'ubl' => $i->customer_vat !== null && UblInvoice::sellerIsConfigured(),
            ])->all(),
        ]);
    }

    /** Facture électronique UBL (Peppol BIS 3.0), pour une facture établie au nom d'une entreprise. */
    public function ubl(Request $request, Booking $booking, Invoice $invoice): HttpResponse
    {
        $this->ensureOwner($request, $booking);
        abort_if($invoice->customer_vat === null, 404);
        abort_unless(Payment::whereKey($invoice->payment_id)->where('payer_id', $request->user()->id)->exists(), 404);
        abort_unless(UblInvoice::sellerIsConfigured(), 503, 'Les coordonnées du club (CLUB_* dans .env) ne sont pas complètes.');

        return response((new UblInvoice)->toXml($invoice, $booking->public_id), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.xml"',
        ]);
    }

    /** Revanche : même terrain, même heure de Bruxelles, une semaine plus tard. */
    public function rematch(Request $request, Booking $booking): RedirectResponse
    {
        $this->ensureOwner($request, $booking);
        abort_unless(in_array($booking->status, ['confirmed', 'used'], true), 404);
        // La semaine s'ajoute en heure locale : le passage à l'heure d'hiver ne décale pas la revanche.
        $start = Clock::local($booking->starts_at)->addWeek()->utc();
        try {
            $next = $this->bookings->hold($request->user(), $booking->court, $start);
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return to_route('bookings.checkout', $next);
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $this->ensureOwner($request, $booking);
        try {
            $booking = $this->bookings->cancel($booking, $request->user());
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }
        $message = $booking->status === 'cancelled_refunded'
            ? __('Réservation annulée et remboursée. Une note de crédit a été émise.')
            : __('Réservation annulée. Moins de 24 h avant le match, elle n’est pas remboursée.');

        return to_route('bookings.show', $booking)->with('success', $message);
    }

    // La réservation d'un autre joueur répond 404 : un 403 confirmerait qu'elle existe.
    private function ensureOwner(Request $request, Booking $booking): void
    {
        abort_unless($booking->organizer_id === $request->user()->id, 404);
    }

    private function present(Booking $b): array
    {
        $start = Clock::local($b->starts_at);

        return [
            'id' => $b->public_id,
            'court' => $b->court->name,
            'is_arena' => $b->is_arena,
            'date' => $start->locale(app()->getLocale())->isoFormat(__('dddd D MMMM YYYY')),
            'time' => $start->format('H:i').' – '.Clock::local($b->ends_at)->format('H:i'),
            'status' => $b->status,
            'price_cents' => $b->price_cents,
            'vat_rate' => (float) $b->vat_rate,
            'hold_expires_at' => $b->hold_expires_at?->toIso8601String(),
            // Faux à moins de 24 h du match : l'annulation n'est plus remboursée.
            'refundable' => now()->lte($b->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS)),
            'free_cancellation_until' => Clock::local($b->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS))->locale(app()->getLocale())->isoFormat(__('dddd D MMMM [à] HH:mm')),
            'cancellable' => $b->status === 'confirmed' && $b->starts_at->isFuture(),
            'game_mode' => $b->game_mode,
            'arcade_price_cents' => ($supplement = app(SlotService::class)->arcadeSupplement($b->starts_at)) === null ? null : $b->price_cents + $supplement,
        ];
    }
}
