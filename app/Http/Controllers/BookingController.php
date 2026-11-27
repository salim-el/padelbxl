<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreCredit;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\InvoicePdf;
use App\Services\MatchCallService;
use App\Services\StoreCreditService;
use App\Services\StripeCheckout;
use App\Services\StripePayments;
use App\Services\UblInvoice;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
            'invitations' => $invited->map(fn ($p) => [...$this->present($p->booking), 'organizer' => $p->guest_email === null ? strtok($p->booking->organizer->name, ' ') : $p->booking->organizer->name, 'share_cents' => $p->share_cents, 'share_status' => $p->status])->all(),
            'store_credit' => $this->credit($user),
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
            'products' => Product::where('is_active', true)->where(fn ($q) => $q->whereNull('sport')->orWhere('sport', $booking->court->sport))
                ->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind', 'price_cents'])->all(),
            // Partage du prix : 3 invités au padel, 9 au foot à 5 ; l'appel aux membres reste réservé au padel.
            'sport' => $booking->court->sport,
            'max_guests' => $booking->court->maxPlayers() - 1,
            // F11 : l'appel propose par défaut le niveau de l'organisateur, à un point près.
            'my_level' => $request->user()->level !== null ? (int) round((float) $request->user()->level) : null,
            // F15 : l'avoir se déduit en priorité, la page annonce ce qui reste à payer par carte.
            'credit_cents' => StoreCreditService::balance($request->user()),
        ]);
    }

    public function pay(Request $request, Booking $booking, MatchCallService $calls, StripePayments $stripe, StripeCheckout $checkout): SymfonyResponse
    {
        $this->ensureOwner($request, $booking);
        // Autres joueurs du sport : 3 au padel, 9 au foot à 5. Le service refuse les places ouvertes au foot.
        $others = $booking->court->maxPlayers() - 1;
        $data = $request->validate([
            'accept_terms' => ['accepted'],
            // shortcut: 4 articles au plus, la taille d'une partie de padel (aussi en base) ; le catalogue livré n'a que du padel.
            // À passer à maxPlayers() le jour où un article sans sport est proposé au foot.
            'items' => ['nullable', 'array'], 'items.*' => ['integer', 'min:0', 'max:4'],
            'guests' => ['nullable', 'array', 'max:'.$others], 'guests.*' => ['nullable', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'open_spots' => ['nullable', 'integer', 'min:0', 'max:'.$others],
            // F11 : niveau demandé dans l'appel, obligatoire dès qu'une place est ouverte au club.
            'level_min' => ['required_unless:open_spots,0,null', 'nullable', 'integer', 'between:1,7'],
            'level_max' => ['required_unless:open_spots,0,null', 'nullable', 'integer', 'between:1,7', 'gte:level_min'],
        ]);
        $guests = array_values(array_filter($data['guests'] ?? []));
        $open = (int) ($data['open_spots'] ?? 0);
        // Partager le prix engage l'organisateur à payer les parts restées impayées à 24 h du match.
        if (($guests !== [] || $open > 0) && ! $request->boolean('accept_guarantee')) {
            return back()->withErrors(['accept_guarantee' => __('Acceptez la garantie pour partager le prix.')]);
        }
        $order = ['items' => $data['items'] ?? [], 'guests' => $guests, 'open' => $open, 'level_min' => $data['level_min'] ?? null, 'level_max' => $data['level_max'] ?? null];
        try {
            // F9 : avec Stripe, le joueur paie sur la page de Stripe ; la réservation se confirme à son retour (paid())
            // ou à la notification signée, selon ce qui arrive en premier. Ce que l'avoir couvre en entier ne passe pas par Stripe.
            $card = $stripe->enabled() ? $this->bookings->cardAmount($booking, $request->user(), $order['items'], count($guests), $open) : 0;
            if ($card > 0) {
                if ($booking->status !== 'pending_payment' || $booking->hold_expires_at?->isPast()) {
                    throw new BookingException(__('Les dix minutes sont écoulées. Le créneau a été libéré, rien n’a été débité.'));
                }

                return Inertia::location($checkout->start($booking, $request->user(), 'booking', $card, $this->label($booking),
                    $guests !== [] || $open > 0, route('bookings.checkout', $booking), $order)->url);
            }
            // Avec Stripe, l'avoir doit encore tout couvrir à la confirmation : received 0 refuse un avoir dépensé entre-temps.
            $this->bookings->confirmPayment($booking, $request->user(), $order['items'], 'card', $guests, $open, received: $stripe->enabled() ? 0 : null);
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return $this->confirmed($booking, $order, $calls);
    }

    /**
     * F9 : retour de la page de Stripe. La session est relue chez Stripe, jamais crue sur parole ; elle doit viser
     * cette réservation et ce compte. Un paiement qui ne peut plus être honoré est remboursé tout de suite.
     */
    public function paid(Request $request, Booking $booking, StripePayments $stripe, StripeCheckout $checkout): RedirectResponse
    {
        $id = $request->query('session_id');
        abort_unless($stripe->enabled() && is_string($id) && str_starts_with($id, 'cs_'), 404);
        try {
            $session = $stripe->session($id);
        } catch (BookingException $e) {
            return to_route('bookings.index')->withErrors(['booking' => $e->getMessage()]);
        }
        abort_unless(($session->metadata['booking'] ?? null) === $booking->public_id && ($session->metadata['user'] ?? null) === (string) $request->user()->id, 404);
        $purpose = (string) ($session->metadata['purpose'] ?? 'booking');
        $done = in_array($purpose, ['share', 'join'], true) ? route('bookings.share', $booking) : route('bookings.show', $booking);
        $failed = match ($purpose) {
            'booking' => route('bookings.checkout', $booking),
            'join' => route('open-matches.index'),
            default => $done,
        };
        if ($session->payment_status !== 'paid') {
            return redirect($failed)->withErrors(['booking' => __('Le paiement n’a pas abouti. Rien n’a été débité.')]);
        }
        try {
            $order = $checkout->complete($session);
        } catch (BookingException $e) {
            return redirect($purpose === 'booking' ? $done : $failed)->withErrors(['booking' => $e->getMessage()]);
        }
        // null : déjà terminé par la notification de Stripe ou par un premier retour.
        if ($order === null) {
            return redirect($done);
        }

        return redirect($done)->with('success', match ($purpose) {
            'share' => __('Part réglée. Votre QR code est prêt.'),
            'join' => __('Place réservée et part réglée. Votre QR code est prêt.'),
            'move' => __('Réservation déplacée. Le supplément de :amount est payé.', ['amount' => $this->euros($session->payment_intent->amount_received)]),
            default => $order['guests'] === [] ? __('Réservation confirmée. Votre facture est disponible.') : __('Réservation confirmée. Vos partenaires ont reçu leur invitation par e-mail.'),
        });
    }

    /**
     * Après le paiement : appel aux joueurs du niveau demandé, puis message de confirmation.
     *
     * @param  array{guests: list<string>, open: int, level_min: int|null, level_max: int|null}  $order
     */
    private function confirmed(Booking $booking, array $order, MatchCallService $calls): RedirectResponse
    {
        // Un e-mail qui échoue ne doit pas annuler une réservation payée.
        if ($order['open'] > 0) {
            try {
                $calls->publish($booking, (int) $order['level_min'], (int) $order['level_max']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return to_route('bookings.show', $booking)->with('success', $order['guests'] === []
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
            // Un inconnu venu par une place ouverte ne voit que le prénom : l'organisateur ne l'a pas choisi.
            'organizer' => $player->guest_email === null ? strtok($booking->organizer->name, ' ') : $booking->organizer->name,
            // Le message de refus dépend du sport : seule une place de padel est proposée aux membres.
            'sport' => $booking->court->sport,
            'share_cents' => $player->share_cents,
            'share_status' => $player->status,
            'payable' => $player->status === 'invited' && $booking->status === 'confirmed' && $booking->starts_at->isFuture(),
            'declinable' => $player->status === 'invited' && $booking->status === 'confirmed' && now()->lte($booking->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS)),
            'qr' => $qr,
            'qr_window' => $pass ? Clock::local($pass->valid_from)->format('H:i').' – '.Clock::local($pass->valid_until)->format('H:i') : null,
            'invoices' => $booking->invoices()->whereIn('payment_id', Payment::where('booking_player_id', $player->id)->where('payer_id', $request->user()->id)->select('id'))->with('creditNotes')->get()
                ->map(fn ($i) => ['number' => $i->number, 'total_cents' => $i->total_cents, 'vat_cents' => $i->vat_cents, 'credit_notes' => $i->creditNotes->pluck('number')->all(),
                    'ubl' => $i->customer_vat !== null && UblInvoice::sellerIsConfigured()])->all(),
        ]);
    }

    public function payShare(Request $request, Booking $booking, StripePayments $stripe, StripeCheckout $checkout): SymfonyResponse
    {
        abort_if(BookingService::invitedPlayer($booking, $request->user()) === null, 404);
        $request->validate(['accept_terms' => ['accepted']]);
        try {
            // F9 : avec Stripe, l'invité paie sa part sur la page de Stripe, sauf si son avoir la couvre.
            $card = $stripe->enabled() ? $this->bookings->shareAmount($booking, $request->user()) : 0;
            if ($card > 0) {
                return Inertia::location($checkout->start($booking, $request->user(), 'share', $card, $this->label($booking), false, route('bookings.share', $booking))->url);
            }
            $this->bookings->payShare($booking, $request->user(), received: $stripe->enabled() ? 0 : null);
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return to_route('bookings.share', $booking)->with('success', __('Part réglée. Votre QR code est prêt.'));
    }

    public function declineShare(Request $request, Booking $booking): RedirectResponse
    {
        abort_if(BookingService::invitedPlayer($booking, $request->user()) === null, 404);
        try {
            $this->bookings->declineShare($booking, $request->user());
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        // Les places ouvertes n'existent qu'au padel : au foot, la part refusée reste à l'organisateur.
        return to_route('bookings.index')->with('success', $booking->court->sport === 'padel'
            ? __('Invitation refusée. Votre place est proposée aux autres membres du club.')
            : __('Invitation refusée. Votre part reste à la charge de l’organisateur.'));
    }

    public function show(Request $request, Booking $booking): Response
    {
        $this->ensureOwner($request, $booking);
        // Seules les factures payées par l'organisateur : celles d'un partenaire sont à son nom et ne regardent que lui.
        $booking->load(['court', 'players.accessPass', 'players.user', 'items.product',
            'invoices' => fn ($q) => $q->with('creditNotes')->whereIn('payment_id', Payment::where('payer_id', $request->user()->id)->select('id'))]);
        $player = $booking->players->firstWhere('is_organizer', true);
        $pass = $player?->accessPass;
        $qr = $pass && $pass->revoked_at === null && in_array($booking->status, ['confirmed', 'used'], true)
            ? (new QRCode)->render(BookingService::passToken($player))
            : null;
        $padel = $booking->court->sport === 'padel';

        return Inertia::render('bookings/show', [
            'booking' => $this->present($booking),
            'qr' => $qr,
            'qr_window' => $pass ? Clock::local($pass->valid_from)->format('H:i').' – '.Clock::local($pass->valid_until)->format('H:i') : null,
            // F14 : seule une partie jouée seul se déplace, et jusqu'à 24 h avant.
            'movable' => $booking->status === 'confirmed' && $booking->walk_in_name === null && $booking->players->count() < 2
                && now()->lte($booking->starts_at->subHours(BookingService::FREE_CANCELLATION_HOURS)),
            'date_local' => Clock::local($booking->starts_at)->toDateString(),
            'items' => $booking->items->map(fn ($i) => ['name' => $i->product->name, 'quantity' => $i->quantity, 'total_cents' => $i->quantity * $i->unit_price_cents])->all(),
            // Liste vide quand l'organisateur a tout payé seul : l'écran n'affiche alors pas le partage.
            'players' => $booking->players->count() < 2 ? [] : $booking->players->sortByDesc('is_organizer')->values()->map(fn ($p) => [
                // L'organisateur revoit l'adresse qu'il a tapée, jamais le nom du compte : il n'apprend pas qui est inscrit au club.
                // Sans adresse, c'est une place ouverte au padel ; au foot, une invitation refusée, à la charge de l'organisateur.
                'name' => $p->is_organizer ? $request->user()->name : $p->guest_email,
                'open' => $padel && ! $p->is_organizer && $p->guest_email === null, 'declined' => ! $padel && ! $p->is_organizer && $p->guest_email === null,
                'taken' => $p->user_id !== null, 'is_organizer' => $p->is_organizer, 'share_cents' => $p->share_cents, 'status' => $p->status,
            ])->all(),
            'invoices' => $booking->invoices->map(fn ($i) => [
                'number' => $i->number, 'total_cents' => $i->total_cents, 'vat_cents' => $i->vat_cents, 'credit_notes' => $i->creditNotes->pluck('number')->all(),
                'ubl' => $i->customer_vat !== null && UblInvoice::sellerIsConfigured(),
            ])->all(),
        ]);
    }

    /** Facture électronique UBL (Peppol BIS 3.0), pour une facture établie au nom d'une entreprise : organisateur ou partenaire, seul le payeur la télécharge. */
    public function ubl(Request $request, Booking $booking, Invoice $invoice): HttpResponse
    {
        abort_if($invoice->customer_vat === null, 404);
        abort_unless(Payment::whereKey($invoice->payment_id)->where('payer_id', $request->user()->id)->exists(), 404);
        abort_unless(UblInvoice::sellerIsConfigured(), 503, 'Les coordonnées du club (CLUB_* dans .env) ne sont pas complètes.');

        return response((new UblInvoice)->toXml($invoice, $booking->public_id), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.xml"',
        ]);
    }

    /** Facture PDF, au nom de celui qui a payé. */
    public function pdf(Request $request, Booking $booking, Invoice $invoice): HttpResponse
    {
        $this->ensurePayerOrStaff($request, $invoice);

        return $this->pdfResponse((new InvoicePdf)->render($invoice), $invoice->number);
    }

    /** Note de crédit PDF. Une facture en porte une par remboursement : l'adresse nomme celle qui est demandée. */
    public function creditNotePdf(Request $request, Booking $booking, Invoice $invoice, CreditNote $creditNote): HttpResponse
    {
        $this->ensurePayerOrStaff($request, $invoice);

        return $this->pdfResponse((new InvoicePdf)->render($invoice, $creditNote), $creditNote->number);
    }

    // Une facture ne regarde que celui qui l'a payée (organisateur ou partenaire) et le personnel du club.
    private function ensurePayerOrStaff(Request $request, Invoice $invoice): void
    {
        abort_unless(Payment::whereKey($invoice->payment_id)->where('payer_id', $request->user()->id)->exists() || $request->user()->can('manage-club'), 403);
        // Sans les coordonnées du club, le document n'aurait pas les mentions obligatoires du vendeur.
        abort_unless(UblInvoice::sellerIsConfigured(), 503, 'Les coordonnées du club (CLUB_* dans .env) ne sont pas complètes.');
    }

    private function pdfResponse(string $pdf, string $number): HttpResponse
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$number.'.pdf"',
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

    /** F14 : le nouveau créneau est choisi dans le planning, ouvert avec ?move=. */
    public function move(Request $request, Booking $booking, StripePayments $stripe, StripeCheckout $checkout): SymfonyResponse
    {
        $this->ensureOwner($request, $booking);
        $data = $request->validate(['court_id' => ['required', 'integer', 'exists:courts,id'], 'starts_at' => ['required', 'date']]);
        $before = $booking->price_cents;
        $court = Court::findOrFail($data['court_id']);
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        try {
            // F9 : un supplément que l'avoir ne couvre pas se paie sur la page de Stripe ; le déplacement se fait au retour.
            $card = $stripe->enabled() ? $this->bookings->moveAmount($booking, $request->user(), $court, $start) : 0;
            if ($card > 0) {
                return Inertia::location($checkout->start($booking, $request->user(), 'move', $card,
                    'Supplément de déplacement : '.$court->name.' – '.Clock::local($start)->format('d/m/Y H:i'), false,
                    route('bookings.show', $booking), ['court' => $court->id, 'starts_at' => $start->toIso8601String()])->url);
            }
            $booking = $this->bookings->move($booking, $request->user(), $court, $start, received: $stripe->enabled() ? 0 : null);
        } catch (BookingException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }
        $diff = $booking->price_cents - $before;
        $amount = $this->euros(abs($diff));
        $message = match (true) {
            $diff > 0 => __('Réservation déplacée. Le supplément de :amount est payé.', ['amount' => $amount]),
            $diff < 0 => __('Réservation déplacée. :amount vous sont remboursés, avec une note de crédit.', ['amount' => $amount]),
            default => __('Réservation déplacée, sans frais.'),
        };

        return to_route('bookings.show', $booking)->with('success', $message);
    }

    /** Libellé de la ligne payée chez Stripe : terrain et heure de Bruxelles. */
    private function label(Booking $booking): string
    {
        return $booking->court->name.' – '.Clock::local($booking->starts_at)->format('d/m/Y H:i');
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }

    // La réservation d'un autre joueur répond 404 : un 403 confirmerait qu'elle existe.
    private function ensureOwner(Request $request, Booking $booking): void
    {
        abort_unless($booking->organizer_id === $request->user()->id, 404);
    }

    /** F15 : solde de l'avoir du joueur et date à laquelle le premier de ses avoirs expire. */
    private function credit(User $user): array
    {
        $first = StoreCredit::usable()->where('user_id', $user->id)->min('expires_at');

        return [
            'cents' => StoreCreditService::balance($user),
            'first_expires' => $first ? Clock::local(CarbonImmutable::parse($first))->locale(app()->getLocale())->isoFormat(__('D MMMM YYYY')) : null,
        ];
    }

    private function present(Booking $b): array
    {
        $start = Clock::local($b->starts_at);

        return [
            'id' => $b->public_id,
            'court' => $b->court->name,
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
        ];
    }
}
