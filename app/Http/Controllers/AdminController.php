<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\ClubSetting;
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
    private const ROLE_LABELS = ['player' => 'Joueur', 'manager' => 'Gérant', 'admin' => 'Administrateur'];

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
        $slots = Court::where('is_active', true)->count() * count(Clock::slots());

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
            'settings' => ['opens_at' => substr(app(ClubSetting::class)->opens_at, 0, 5), 'closes_at' => substr(app(ClubSetting::class)->closes_at, 0, 5),
                'slot_minutes' => app(ClubSetting::class)->slot_minutes],
            'courts' => Court::orderBy('id')->get(['id', 'name'])->all(),
            // Tarifs en cours et à venir ; ceux qui sont clos restent en base pour l'historique des prix.
            'rules' => PricingRule::where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
                ->orderBy('valid_from')->orderBy('start_time')->get()->map(fn (PricingRule $r) => [
                    'id' => $r->id, 'name' => $r->name, 'start' => substr($r->start_time, 0, 5), 'end' => substr($r->end_time, 0, 5),
                    'price_cents' => $r->price_cents, 'vat_rate' => (float) $r->vat_rate,
                    'valid_from' => $r->valid_from->format('d/m/Y'), 'valid_until' => $r->valid_until?->format('d/m/Y'),
                    'upcoming' => $r->valid_from->isFuture(),
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
            'staff-invited', 'staff-role-changed' => isset($p['old']) ? self::ROLE_LABELS[$p['old']].' → '.self::ROLE_LABELS[$p['role']] : self::ROLE_LABELS[$p['role']],
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

    /**
     * F24 : nouveau tarif, tout de suite ou à une date future (heures creuses, nouvelle saison).
     * Le tarif le plus récent l'emporte sur les mêmes heures ; deux tarifs qui démarrent le même jour ne se chevauchent pas.
     */
    public function storePricingRule(Request $request): RedirectResponse
    {
        $this->ensureStaff($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'start_time' => ['required', 'date_format:H:i', 'after_or_equal:'.substr(app(ClubSetting::class)->opens_at, 0, 5)],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time', 'before_or_equal:'.substr(app(ClubSetting::class)->closes_at, 0, 5)],
            'price_cents' => ['required', 'integer', 'min:100', 'max:100000'],
            // 6 % : accès aux installations sportives ; 21 % : par exemple un cours vendu seul (circulaire 2022/C/118).
            'vat_rate' => ['required', 'in:6,21'],
            'valid_from' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $overlap = PricingRule::whereDate('valid_from', $data['valid_from'])
            ->where('start_time', '<', $data['end_time'].':00')->where('end_time', '>', $data['start_time'].':00')->exists();
        if ($overlap) {
            return back()->withErrors(['pricing' => 'Un autre tarif commence le même jour sur ces heures.']);
        }
        $rule = PricingRule::create($data);
        activity('staff')->performedOn($rule)->event('price-rule-created')
            ->withProperties(['price_cents' => $rule->price_cents, 'vat_rate' => $data['vat_rate'], 'valid_from' => $data['valid_from']])->log('Tarif créé');

        return back()->with('success', "Tarif « {$rule->name} » enregistré.");
    }

    /** F24 : clôturer un tarif à une date ; les réservations déjà payées gardent leur prix. */
    public function closePricingRule(Request $request, PricingRule $rule): RedirectResponse
    {
        $this->ensureStaff($request);
        $data = $request->validate(['valid_until' => ['required', 'date', 'after_or_equal:today', 'after_or_equal:'.$rule->valid_from->toDateString()]]);
        $rule->update($data);
        activity('staff')->performedOn($rule)->event('price-rule-closed')->withProperties($data)->log('Tarif clôturé');

        return back()->with('success', "Tarif « {$rule->name} » valable jusqu'au ".CarbonImmutable::parse($data['valid_until'])->format('d/m/Y').'.');
    }

    /**
     * F24 : heures d'ouverture et durée des créneaux. Refusé si une réservation à venir ne tombe plus sur un créneau :
     * le gérant la déplace ou l'annule d'abord, comme pour une fermeture de terrain.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->ensureStaff($request);
        $data = $request->validate([
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
            'slot_minutes' => ['required', 'integer', 'in:60,90,120'],
        ]);
        $next = new ClubSetting($data);
        if ($next->slots() === []) {
            return back()->withErrors(['settings' => 'Aucun créneau ne tient entre ces heures.']);
        }
        $misplaced = Booking::whereIn('status', ['pending_payment', 'confirmed'])->where('starts_at', '>', now())->get(['starts_at', 'ends_at'])
            ->filter(fn (Booking $b) => ! in_array(Clock::local($b->starts_at)->format('H:i'), $next->slots(), true)
                || $b->starts_at->diffInMinutes($b->ends_at) !== $next->slot_minutes)->count();
        if ($misplaced > 0) {
            return back()->withErrors(['settings' => "$misplaced réservation(s) à venir ne tomberai(en)t plus sur un créneau. Déplacez-les ou annulez-les d’abord."]);
        }
        $settings = ClubSetting::firstOrFail();
        $old = $settings->only(['opens_at', 'closes_at', 'slot_minutes']);
        $settings->update($data);
        app()->forgetScopedInstances();
        activity('staff')->performedOn($settings)->event('club-settings-updated')->withProperties(['old' => $old, 'new' => $data])->log('Horaires modifiés');

        return back()->with('success', 'Horaires enregistrés.');
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
