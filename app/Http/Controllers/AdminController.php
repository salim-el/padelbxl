<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\AccountStatusService;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureStaff($request);
        $search = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $day = CarbonImmutable::now(Clock::ZONE)->startOfDay();
        $month = $day->startOfMonth();

        $today = Booking::with('court', 'organizer')
            ->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())
            ->whereIn('status', ['pending_payment', 'confirmed', 'used', 'missed', 'cancelled_refunded', 'cancelled_unrefunded'])
            ->orderBy('starts_at')->orderBy('court_id')->get();
        $played = $today->whereIn('status', ['confirmed', 'used', 'missed'])->count();
        $slots = Court::where('is_active', true)->count() * count(Clock::SLOTS);

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'bookings_today' => $played,
                'occupancy' => $slots > 0 ? (int) round(100 * $played / $slots) : 0,
                'revenue_today_cents' => $this->revenue($day, $day->addDay()),
                'revenue_month_cents' => $this->revenue($month, $month->addMonth()),
            ],
            'today' => $today->map(fn (Booking $b) => [
                'id' => $b->public_id,
                'court' => $b->court->name,
                'time' => Clock::local($b->starts_at)->format('H:i').' – '.Clock::local($b->ends_at)->format('H:i'),
                'player' => $b->walk_in_name ? $b->walk_in_name.' (comptoir)' : $b->organizer->name,
                'status' => $b->status,
            ])->all(),
            'search' => $search,
            'courts' => Court::orderBy('id')->get(['id', 'name'])->all(),
            'rules' => PricingRule::orderBy('start_time')->get()->map(fn (PricingRule $r) => [
                'id' => $r->id, 'name' => $r->name, 'start' => substr($r->start_time, 0, 5), 'end' => substr($r->end_time, 0, 5),
                'price_cents' => $r->price_cents,
            ])->all(),
            'closures' => CourtClosure::with('court')->where('ends_at', '>', now())->orderBy('starts_at')->get()->map(fn (CourtClosure $c) => [
                'id' => $c->id, 'court' => $c->court->name, 'reason' => $c->reason,
                'from' => Clock::local($c->starts_at)->format('d/m/Y H:i'), 'to' => Clock::local($c->ends_at)->format('d/m/Y H:i'),
            ])->all(),
            'users' => User::query()
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")))
                ->orderBy('role')->orderBy('name')
                ->paginate(15)->withQueryString()->through(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'verified' => $u->email_verified_at !== null,
                    'role' => $u->role,
                    'status' => $u->status,
                    'created_at' => $u->created_at?->setTimezone(Clock::ZONE)->format('d/m/Y'),
                ]),
        ]);
    }

    /** Journal des actions sensibles (T16) : lecture seule, du plus récent au plus ancien. */
    public function journal(Request $request): Response
    {
        $this->ensureStaff($request);
        $log = $request->validate(['log' => ['nullable', 'in:staff,api']])['log'] ?? null;

        return Inertia::render('admin/journal', [
            'log' => $log,
            'entries' => Activity::with('causer', 'subject')
                ->when($log, fn ($q) => $q->where('log_name', $log))
                ->latest('id')
                ->paginate(25)->withQueryString()->through(fn (Activity $a) => [
                    'id' => $a->id,
                    'at' => $a->created_at->setTimezone(Clock::ZONE)->format('d/m/Y H:i'),
                    'log' => $a->log_name,
                    'event' => $a->event,
                    'description' => $a->description,
                    'causer' => $a->causer?->name,
                    'subject' => $this->subjectLabel($a),
                    'details' => $this->details($a),
                ]),
        ]);
    }

    // L'objet visé peut avoir été supprimé (fermeture rouverte, jeton révoqué) : on garde alors une étiquette neutre.
    private function subjectLabel(Activity $a): ?string
    {
        return match (true) {
            $a->subject instanceof User, $a->subject instanceof PricingRule => $a->subject->name,
            $a->subject instanceof CourtClosure => $a->subject->court?->name,
            $a->subject instanceof Booking => $a->subject->walk_in_name ?? $a->subject->court?->name,
            $a->subject !== null => $a->subject->name ?? null,
            $a->event === 'closure-deleted' => Court::find($a->properties['court_id'] ?? null)?->name,
            default => null,
        };
    }

    private function details(Activity $a): ?string
    {
        $p = $a->properties;

        return match ($a->event) {
            'price-updated' => number_format($p['old_cents'] / 100, 2, ',', ' ').' € → '.number_format($p['new_cents'] / 100, 2, ',', ' ').' €',
            'closure-deleted' => Clock::local(CarbonImmutable::parse($p['from']))->format('d/m/Y H:i').' → '.Clock::local(CarbonImmutable::parse($p['to']))->format('d/m/Y H:i'),
            default => null,
        };
    }

    /** F28 : suspension motivée, pour une durée fixe ou jusqu'à nouvelle décision. Le joueur reçoit le motif. */
    public function suspend(Request $request, User $user, AccountStatusService $accounts): RedirectResponse
    {
        $this->ensurePlayer($request, $user);
        abort_unless($user->status === 'active', 422);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'days' => ['nullable', 'integer', 'in:1,3,7,14,30,90'],
        ]);
        $accounts->suspend($user, $request->user(), $data['reason'], $data['days'] ?? null);

        return back()->with('success', "Le compte de {$user->name} est suspendu. Le motif lui est envoyé par e-mail.");
    }

    public function reactivate(Request $request, User $user, AccountStatusService $accounts): RedirectResponse
    {
        $this->ensurePlayer($request, $user);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        try {
            $accounts->reactivate($user, $request->user(), $data['reason'] ?? 'Réactivé par le personnel.');
        } catch (BookingException $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        return back()->with('success', "Le compte de {$user->name} est réactivé.");
    }

    /** F28 : révocation définitive pour fraude, après vérification. */
    public function revoke(Request $request, User $user, AccountStatusService $accounts): RedirectResponse
    {
        $this->ensurePlayer($request, $user);
        abort_if($user->status === 'revoked', 422);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $accounts->revoke($user, $request->user(), $data['reason']);

        return back()->with('success', "Le compte de {$user->name} est révoqué.");
    }

    // Chiffre d'affaires TTC encaissé : factures émises moins notes de crédit sur la période.
    private function revenue(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $sum = fn (string $table) => (int) DB::table($table)->where('issued_at', '>=', $from->utc())->where('issued_at', '<', $to->utc())->sum('total_cents');

        return $sum('invoices') - $sum('credit_notes');
    }

    /** Nouveau prix d'une règle. Les réservations déjà faites gardent le prix figé au moment du paiement. */
    public function updatePrice(Request $request, PricingRule $rule): RedirectResponse
    {
        $this->ensureStaff($request);
        $data = $request->validate(['price_cents' => ['required', 'integer', 'min:100', 'max:100000']]);
        $old = $rule->price_cents;
        $rule->update(['price_cents' => $data['price_cents']]);
        activity('staff')->performedOn($rule)->event('price-updated')
            ->withProperties(['old_cents' => $old, 'new_cents' => $rule->price_cents])->log('Tarif modifié');

        return back()->with('success', "Tarif « {$rule->name} » mis à jour.");
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        $this->ensureStaff($request);
        $data = $request->validate([
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'from' => ['required', 'date_format:Y-m-d\TH:i'],
            'to' => ['required', 'date_format:Y-m-d\TH:i', 'after:from'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['from'], Clock::ZONE)->utc();
        $to = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['to'], Clock::ZONE)->utc();
        // Une fermeture ne doit jamais faire disparaître une réservation payée sans que le gérant l'annule d'abord.
        // Même verrou que la réservation : aucune partie ne peut se glisser entre le contrôle et la création.
        $closure = DB::transaction(function () use ($data, $from, $to, $request) {
            BookingService::lockCourt((int) $data['court_id']);
            $conflicts = Booking::where('court_id', $data['court_id'])->whereIn('status', Booking::ACTIVE)
                ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->count();

            return $conflicts > 0 ? $conflicts : CourtClosure::create(['court_id' => $data['court_id'], 'starts_at' => $from, 'ends_at' => $to, 'reason' => $data['reason'], 'created_by' => $request->user()->id]);
        });
        if (is_int($closure)) {
            return back()->withErrors(['closure' => "$closure réservation(s) active(s) sur cette période. Annulez-les d’abord."]);
        }
        activity('staff')->performedOn($closure)->event('closure-created')->log('Fermeture de terrain ajoutée');

        return back()->with('success', 'Fermeture enregistrée. Le planning n’affiche plus ces créneaux.');
    }

    public function destroyClosure(Request $request, CourtClosure $closure): RedirectResponse
    {
        $this->ensureStaff($request);
        $closure->delete();
        activity('staff')->performedOn($closure)->event('closure-deleted')
            ->withProperties(['court_id' => $closure->court_id, 'from' => $closure->starts_at, 'to' => $closure->ends_at])->log('Fermeture de terrain supprimée');

        return back()->with('success', 'Fermeture supprimée. Les créneaux sont de nouveau réservables.');
    }

    private function ensureStaff(Request $request): void
    {
        abort_unless($request->user()->can('manage-club'), 403);
    }

    // Le personnel ne gère que les comptes joueurs : pas de suspension d'un collègue ni de soi-même.
    private function ensurePlayer(Request $request, User $user): void
    {
        $this->ensureStaff($request);
        abort_unless($user->role === 'player', 403);
    }
}
