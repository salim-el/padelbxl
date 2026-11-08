<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\AccessPass;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingPlayer;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\ShareInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class BookingService
{
    public const HOLD_MINUTES = 10;

    public const MAX_ACTIVE = 3;

    public const FREE_CANCELLATION_HOURS = 24;

    public const MAX_PLAYERS = 4;

    /** Invitations par organisateur et par jour : quatre parties partagées, pas un relais d'e-mails. */
    public const MAX_INVITATIONS_PER_DAY = 12;

    public function __construct(private SlotService $slots) {}

    /** Libère les créneaux bloqués dont les dix minutes sont écoulées. */
    public function expireHolds(): int
    {
        $ids = Booking::where('status', 'pending_payment')->where('hold_expires_at', '<', now())->pluck('id');
        foreach ($ids as $id) {
            $this->transition(Booking::find($id), 'expired', 'Dix minutes sans paiement.');
        }

        return $ids->count();
    }

    /** @param array{walk_in_name: string, walk_in_contact: string}|null $walkIn client du comptoir, sans compte */
    public function hold(User $user, Court $court, CarbonImmutable $startUtc, ?array $walkIn = null): Booking
    {
        if ($user->status !== 'active' || $user->email_verified_at === null) {
            throw new BookingException(__('Votre compte doit être actif et votre e-mail confirmé pour réserver.'));
        }
        if (! in_array(Clock::local($startUtc)->format('H:i'), Clock::SLOTS, true) || ! $startUtc->isFuture() || $startUtc->gt(now()->addDays(30))) {
            throw new BookingException(__('Ce créneau n’existe pas ou n’est plus réservable.'));
        }
        $rule = $this->slots->priceFor($startUtc);
        if ($rule === null || ! $court->is_active) {
            throw new BookingException(__('Ce terrain n’est pas réservable à cette heure.'));
        }
        $end = $startUtc->addMinutes(Clock::SLOT_MINUTES);
        if (CourtClosure::where('court_id', $court->id)->where('starts_at', '<', $end)->where('ends_at', '>', $startUtc)->exists()) {
            throw new BookingException(__('Ce terrain est fermé sur ce créneau.'));
        }

        $this->expireHolds();

        return DB::transaction(function () use ($user, $court, $startUtc, $rule, $walkIn) {
            // La limite de trois réservations vise un joueur, pas le gérant qui encode pour des clients au comptoir.
            $active = Booking::where('organizer_id', $user->id)->whereNull('walk_in_name')->whereIn('status', ['pending_payment', 'confirmed'])->where('starts_at', '>', now())->count();
            if ($walkIn === null && $active >= self::MAX_ACTIVE) {
                throw new BookingException(__('Vous avez déjà trois réservations à venir.'));
            }
            try {
                // Point de sauvegarde : un refus de la contrainte ne casse pas la transaction englobante.
                $booking = DB::transaction(fn () => Booking::create([
                    'public_id' => (string) Str::uuid(),
                    'court_id' => $court->id,
                    'organizer_id' => $user->id,
                    'pricing_rule_id' => $rule->id,
                    'starts_at' => $startUtc,
                    'ends_at' => $startUtc->addMinutes(Clock::SLOT_MINUTES),
                    'status' => 'pending_payment',
                    'price_cents' => $this->slots->priceCents($rule, $startUtc),
                    'vat_rate' => $rule->vat_rate,
                    'is_arena' => false,
                    'hold_expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                    ...($walkIn ?? []),
                ]));
            } catch (QueryException $e) {
                // 23P01 : la contrainte d'exclusion refuse un chevauchement.
                if ($e->getCode() === '23P01') {
                    throw new BookingException(__('Ce créneau vient d’être réservé par quelqu’un d’autre.'));
                }
                throw $e;
            }
            BookingPlayer::create(['booking_id' => $booking->id, 'user_id' => $user->id, 'is_organizer' => true, 'share_cents' => $booking->price_cents, 'status' => 'invited']);
            $this->history($booking, null, 'pending_payment', 'Réservation créée.', $user->id);

            return $booking;
        });
    }

    /**
     * Réservation au comptoir : créneau bloqué puis payé sur place (espèces ou terminal) dans la même transaction,
     * donc jamais de créneau à moitié réservé si le paiement échoue.
     */
    public function counter(User $staff, Court $court, CarbonImmutable $startUtc, string $name, string $contact, ?string $gameMode, string $method): Booking
    {
        return DB::transaction(function () use ($staff, $court, $startUtc, $name, $contact, $gameMode, $method) {
            $booking = $this->hold($staff, $court, $startUtc, ['walk_in_name' => $name, 'walk_in_contact' => $contact]);

            return $this->confirmPayment($booking, $staff, $gameMode, [], $method);
        });
    }

    /**
     * Paiement simulé de l'alpha. En production, la confirmation viendra de la notification signée de Stripe.
     *
     * @param  array<int, int>  $items  quantité par identifiant d'article
     * @param  list<string>  $guests  e-mails des joueurs qui paient leur part, trois au plus
     */
    public function confirmPayment(Booking $booking, User $user, ?string $gameMode = null, array $items = [], string $method = 'card', array $guests = []): Booking
    {
        return DB::transaction(function () use ($booking, $user, $gameMode, $items, $method, $guests) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($user->status !== 'active') {
                throw new BookingException(__('Votre compte est suspendu. Le paiement est refusé.'));
            }
            if ($booking->status !== 'pending_payment' || $booking->hold_expires_at?->isPast()) {
                throw new BookingException(__('Les dix minutes sont écoulées. Le créneau a été libéré, rien n’a été débité.'));
            }
            $player = $booking->players()->where('is_organizer', true)->firstOrFail();
            // Un mode arcade remplace le prix classique par le tarif arcade du même créneau (+14 €).
            if ($gameMode !== null && $gameMode !== 'classic') {
                $rule = $this->slots->priceFor($booking->starts_at, true);
                $supplement = $this->slots->arcadeSupplement($booking->starts_at);
                if ($rule === null || $supplement === null) {
                    throw new BookingException(__('Le mode arcade n’est pas disponible sur ce créneau.'));
                }
                // Le supplément s'ajoute au prix déjà bloqué, remise flash comprise.
                $price = $booking->price_cents + $supplement;
                $booking->update(['is_arena' => true, 'game_mode' => $gameMode, 'pricing_rule_id' => $rule->id, 'price_cents' => $price, 'vat_rate' => $rule->vat_rate]);
                $player->update(['share_cents' => $price]);
            }
            if ($guests !== []) {
                $this->splitShares($booking, $player, $user, $guests);
            }
            $lines = [$this->courtLine($booking, $player)];
            foreach ($this->reserveItems($booking, array_filter($items)) as $item) {
                $lines[] = ['label' => $item->product->name, 'quantity' => $item->quantity, 'unit' => $item->unit_price_cents, 'vat' => (float) $item->vat_rate];
            }
            $this->charge($booking, $player, $user, $lines, $method);
            $player->update(['status' => 'paid']);
            $booking->update(['hold_expires_at' => null]);
            $this->transition($booking, 'confirmed', 'Paiement reçu.', $user->id);

            return $booking->fresh();
        });
    }

    /** Un joueur invité règle sa part : il reçoit sa propre facture et son propre QR code. */
    public function payShare(Booking $booking, User $user): BookingPlayer
    {
        return DB::transaction(function () use ($booking, $user) {
            // Le verrou sur la réservation sérialise ce paiement avec la garantie de l'organisateur.
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $player = self::invitedPlayer($booking, $user) ?? throw new BookingException(__('Cette invitation n’existe pas.'));
            if ($player->status !== 'invited') {
                throw new BookingException(__('Votre part est déjà réglée.'));
            }
            if ($booking->status !== 'confirmed' || ! $booking->starts_at->isFuture()) {
                throw new BookingException(__('Cette partie n’accepte plus de paiement.'));
            }
            if ($user->status !== 'active') {
                throw new BookingException(__('Votre compte est suspendu. Le paiement est refusé.'));
            }
            if ($user->email_verified_at === null) {
                throw new BookingException(__('Votre compte doit être actif et votre e-mail confirmé pour réserver.'));
            }
            // Une invitation envoyée à une adresse sans compte est rattachée au compte créé ensuite avec cette adresse.
            $player->update(['user_id' => $user->id]);
            $this->charge($booking, $player, $user, [$this->courtLine($booking, $player)], 'card');
            $player->update(['status' => 'paid']);

            return $player->fresh();
        });
    }

    /**
     * Carte de garantie : à 24 h du match, l'organisateur paie les parts encore impayées.
     * C'est le moment où une annulation cesse d'être remboursée, le club ne porte donc jamais le risque.
     */
    public function chargeUnpaidShares(): int
    {
        $ids = BookingPlayer::where('status', 'invited')->where('is_organizer', false)
            ->whereHas('booking', fn ($q) => $q->where('status', 'confirmed')->where('starts_at', '<=', now()->addHours(self::FREE_CANCELLATION_HOURS)))
            ->pluck('id');
        $charged = 0;
        foreach ($ids as $id) {
            $charged += DB::transaction(function () use ($id) {
                $booking = Booking::whereKey(BookingPlayer::whereKey($id)->value('booking_id'))->lockForUpdate()->firstOrFail();
                $player = BookingPlayer::findOrFail($id);
                // Relu sous verrou : le joueur a pu payer entre la recherche et le verrou.
                if ($player->status !== 'invited' || $booking->status !== 'confirmed') {
                    return 0;
                }
                $this->charge($booking, $player, $booking->organizer, [$this->courtLine($booking, $player)], 'card');
                $player->update(['status' => 'covered']);

                return 1;
            });
        }

        return $charged;
    }

    /** Ligne invitée de ce joueur sur la réservation, retrouvée par son compte ou par l'adresse invitée. */
    public static function invitedPlayer(Booking $booking, User $user): ?BookingPlayer
    {
        return self::invitationsOf($booking->players()->getQuery(), $user)->first();
    }

    /**
     * Restreint aux invitations de ce joueur. L'adresse invitée ne compte que si son e-mail est confirmé :
     * sinon n'importe qui s'inscrirait avec l'adresse d'un autre pour prendre sa place.
     *
     * @param  Builder<BookingPlayer>  $players
     * @return Builder<BookingPlayer>
     */
    public static function invitationsOf(Builder $players, User $user): Builder
    {
        return $players->where('is_organizer', false)->where(fn ($q) => $q->where('user_id', $user->id)
            ->when($user->email_verified_at !== null, fn ($q) => $q->orWhere(fn ($q) => $q->whereNull('user_id')->whereRaw('lower(guest_email) = ?', [mb_strtolower($user->email)]))));
    }

    /** @param list<string> $guests */
    private function splitShares(Booking $booking, BookingPlayer $organizer, User $user, array $guests): void
    {
        $guests = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $guests)));
        if ($booking->walk_in_name !== null || count($guests) > self::MAX_PLAYERS - 1 || in_array(mb_strtolower($user->email), $guests, true)) {
            throw new BookingException(__('Invitez au plus trois autres joueurs, sans vous compter.'));
        }
        if (now()->gt($booking->starts_at->subHours(self::FREE_CANCELLATION_HOURS))) {
            throw new BookingException(__('Le prix ne se partage que pour un match dans plus de 24 h.'));
        }
        $sentToday = BookingPlayer::where('is_organizer', false)->where('joined_at', '>', now()->subDay())
            ->whereHas('booking', fn ($q) => $q->where('organizer_id', $user->id))->count();
        if ($sentToday + count($guests) > self::MAX_INVITATIONS_PER_DAY) {
            throw new BookingException(__('Vous avez envoyé trop d’invitations aujourd’hui. Réessayez demain.'));
        }
        $share = intdiv($booking->price_cents, count($guests) + 1);
        // Les centimes qui ne se divisent pas restent à l'organisateur.
        $organizer->update(['share_cents' => $booking->price_cents - $share * count($guests)]);
        foreach ($guests as $email) {
            // Un compte dont l'e-mail n'est pas confirmé ne prouve pas qu'il possède l'adresse : l'invitation reste alors à l'adresse.
            $invited = User::whereRaw('lower(email) = ?', [$email])->whereNotNull('email_verified_at')->first();
            BookingPlayer::create([
                // guest_email garde toujours l'adresse tapée : c'est la seule que l'organisateur reverra.
                'booking_id' => $booking->id, 'user_id' => $invited?->id, 'guest_email' => $email,
                'is_organizer' => false, 'share_cents' => $share, 'status' => 'invited',
            ]);
            // Envoyé seulement si le paiement aboutit : une transaction annulée n'invite personne.
            DB::afterCommit(function () use ($booking, $user, $share, $email, $invited) {
                $mail = (new ShareInvitation($booking, $user->name, $share))->locale($invited?->locale ?? app()->getLocale());
                $invited ? $invited->notify($mail) : Notification::route('mail', $email)->notify($mail);
            });
        }
    }

    private function courtLine(Booking $booking, BookingPlayer $player): array
    {
        return ['label' => $booking->court->name.($booking->is_arena ? ' – partie Arena' : '').' – '.Clock::local($booking->starts_at)->format('d/m/Y H:i'), 'quantity' => 1, 'unit' => $player->share_cents, 'vat' => (float) $booking->vat_rate];
    }

    /**
     * Encaisse les lignes, émet la facture au nom de celui qui paie et le QR code du joueur.
     *
     * @param  list<array{label: string, quantity: int, unit: int, vat: float}>  $lines
     */
    private function charge(Booking $booking, BookingPlayer $player, User $payer, array $lines, string $method): Payment
    {
        $amount = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit'], $lines));
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'booking_player_id' => $player->id,
            'payer_id' => $payer->id,
            'method' => $method,
            // Espèces et terminal du club n'ont pas de référence chez un prestataire de paiement.
            'provider_reference' => $method === 'card' ? 'test_'.Str::uuid() : null,
            'amount_cents' => $amount,
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);
        // La TVA se calcule ligne par ligne : 6 % sur le terrain, 21 % sur le matériel.
        foreach ($lines as &$l) {
            $l['total'] = $l['quantity'] * $l['unit'];
            $l['net'] = (int) round($l['total'] / (1 + $l['vat'] / 100));
        }
        unset($l);
        $net = array_sum(array_column($lines, 'net'));
        $invoice = Invoice::create([
            'number' => $this->nextNumber('invoice', 'F'),
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'issued_at' => now(),
            'customer_name' => $booking->walk_in_name ?? $payer->name,
            'customer_company' => $booking->walk_in_name ? null : $payer->company_name,
            'customer_vat' => $booking->walk_in_name ? null : $payer->vat_number,
            'net_cents' => $net,
            'vat_rate' => $booking->vat_rate,
            'vat_cents' => $amount - $net,
            'total_cents' => $amount,
        ]);
        foreach ($lines as $l) {
            InvoiceLine::create([
                'invoice_id' => $invoice->id, 'label' => $l['label'], 'quantity' => $l['quantity'], 'unit_price_cents' => $l['unit'],
                'vat_rate' => $l['vat'], 'net_cents' => $l['net'], 'vat_cents' => $l['total'] - $l['net'], 'total_cents' => $l['total'],
            ]);
        }
        AccessPass::create([
            'booking_player_id' => $player->id,
            'token_hash' => hash('sha256', self::passToken($player)),
            'valid_from' => $booking->starts_at->subMinutes(15),
            'valid_until' => $booking->ends_at,
        ]);

        return $payment;
    }

    public function cancel(Booking $booking, User $user): Booking
    {
        return DB::transaction(function () use ($booking, $user) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->status !== 'confirmed') {
                throw new BookingException(__('Cette réservation ne peut plus être annulée.'));
            }
            if (! $booking->starts_at->isFuture()) {
                throw new BookingException(__('Le créneau a commencé. Seule une demande exceptionnelle reste possible.'));
            }
            $refundable = now()->lte($booking->starts_at->subHours(self::FREE_CANCELLATION_HOURS));
            if ($refundable) {
                foreach ($booking->payments()->where('status', 'succeeded')->get() as $payment) {
                    $invoice = Invoice::where('payment_id', $payment->id)->firstOrFail();
                    $note = CreditNote::create([
                        'number' => $this->nextNumber('credit_note', 'NC'),
                        'invoice_id' => $invoice->id,
                        'issued_at' => now(),
                        'net_cents' => $invoice->net_cents,
                        'vat_cents' => $invoice->vat_cents,
                        'total_cents' => $invoice->total_cents,
                        'reason' => 'Annulation au moins 24 h avant le match.',
                    ]);
                    Refund::create(['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents, 'reason' => 'cancellation_24h', 'status' => 'succeeded', 'credit_note_id' => $note->id]);
                    $payment->update(['status' => 'refunded']);
                }
            }
            if (! $refundable) {
                // Annulée trop tard : la garantie encaisse ici les parts impayées, sans attendre la tâche planifiée.
                foreach ($booking->players()->where('is_organizer', false)->where('status', 'invited')->get() as $player) {
                    $this->charge($booking, $player, $booking->organizer, [$this->courtLine($booking, $player)], 'card');
                    $player->update(['status' => 'covered']);
                }
            }
            AccessPass::whereIn('booking_player_id', $booking->players()->pluck('id'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $booking->update(['cancelled_at' => now()]);
            $this->transition($booking, $refundable ? 'cancelled_refunded' : 'cancelled_unrefunded', $refundable ? 'Annulation au moins 24 h avant.' : 'Annulation moins de 24 h avant.', $user->id);

            return $booking->fresh();
        });
    }

    /**
     * Réserve les articles demandés pour le créneau. Le stock vaut par créneau : un article loué
     * sur une partie qui chevauche celle-ci n'est pas disponible.
     *
     * @param  array<int, int>  $items
     * @return list<BookingItem>
     */
    private function reserveItems(Booking $booking, array $items): array
    {
        $reserved = [];
        // Verrouiller les articles évite que deux paiements simultanés louent la même raquette.
        $products = Product::whereIn('id', array_keys($items))->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $productId => $quantity) {
            $product = $products->get($productId) ?? throw new BookingException(__('Cet article n’est plus proposé.'));
            $used = (int) BookingItem::where('product_id', $product->id)
                ->whereHas('booking', fn ($q) => $q->whereIn('status', Booking::ACTIVE)->where('id', '!=', $booking->id)
                    ->where('starts_at', '<', $booking->ends_at)->where('ends_at', '>', $booking->starts_at))
                ->sum('quantity');
            if ($product->kind === 'rental' && $used + $quantity > $product->stock) {
                throw new BookingException(__('Plus assez de « :product » sur ce créneau : il en reste :left.', ['product' => $product->name, 'left' => max(0, $product->stock - $used)]));
            }
            $reserved[] = BookingItem::create(['booking_id' => $booking->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price_cents' => $product->price_cents, 'vat_rate' => $product->vat_rate])->setRelation('product', $product);
        }

        return $reserved;
    }

    /** Jeton du QR code, recalculable à l'affichage. Seule son empreinte est stockée. */
    public static function passToken(BookingPlayer $player): string
    {
        return hash_hmac('sha256', 'booking-player:'.$player->id, (string) config('app.key'));
    }

    /** Numérotation continue par année : l'upsert verrouille la ligne du compteur jusqu'à la fin de la transaction. */
    private function nextNumber(string $type, string $prefix): string
    {
        $year = (int) now()->format('Y');
        $row = DB::selectOne(
            'INSERT INTO document_sequences (type, year, last_number) VALUES (?, ?, 1)
             ON CONFLICT (type, year) DO UPDATE SET last_number = document_sequences.last_number + 1
             RETURNING last_number',
            [$type, $year]
        );

        return sprintf('%s%d-%06d', $prefix, $year, $row->last_number);
    }

    private function transition(Booking $booking, string $to, string $reason, ?int $by = null): void
    {
        $from = $booking->status;
        $booking->update(['status' => $to]);
        $this->history($booking, $from, $to, $reason, $by);
    }

    private function history(Booking $booking, ?string $from, string $to, string $reason, ?int $by): void
    {
        DB::table('booking_status_histories')->insert([
            'booking_id' => $booking->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'changed_by' => $by, 'created_at' => now(),
        ]);
    }
}
