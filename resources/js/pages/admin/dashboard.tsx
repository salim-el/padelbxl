import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { SPORT_LABELS, euros } from '@/lib/money';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarCheck, Gauge, TrendingUp, Wallet } from 'lucide-react';
import { useState } from 'react';

type Stats = { bookings_today: number; occupancy: number; revenue_today_cents: number; revenue_month_cents: number };
type Row = { id: string; court: string; time: string; player: string; status: string; cancellable: boolean };
type Account = { id: number; name: string; email: string; verified: boolean; role: string; status: string; created_at: string | null };
type Flag = {
    id: number;
    type: string;
    details: string | null;
    user: { id: number; name: string; email: string; status: string };
    created_at: string;
};
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };

const ROLES: Record<string, string> = { player: 'Joueur', manager: 'Gérant', admin: 'Administrateur' };
const ACCOUNT: Record<string, [string, string]> = {
    active: ['Actif', 'bg-[#EEF4D6] text-[#4E6600]'],
    suspended: ['Suspendu', 'bg-[#FCE6E8] text-[#9E2833]'],
    revoked: ['Révoqué', 'bg-[#0E1B2C] text-[#F7F6F1]'],
    pending: ['En attente', 'bg-[#E6ECF2] text-[#17304A]'],
};
// F30 et F18 : motifs des signalements, traduits à l'affichage.
const FLAG_LABELS: Record<string, string> = {
    chargeback: 'Contestation bancaire',
    no_show: 'Absences répétées',
    shared_card: 'Même carte sur plusieurs comptes',
    repeated_cancellations: 'Annulations répétées',
    blocked_identity: 'Identité bloquée',
    late_payment: 'Paiement tardif',
};
const pill = 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold';
// Couleur de la discipline d'un terrain, comme sur la maquette : vert pour le padel, bleu pour le foot à 5.
const SPORT_PILL: Record<string, string> = { padel: 'bg-[#EEF4D6] text-[#4E6600]', foot5: 'bg-[#E3F0F7] text-[#1D5C7A]' };

function Stat({ label, value, icon: Icon }: { label: string; value: string; icon: typeof Gauge }) {
    return (
        <div className="bg-card flex items-center gap-4 rounded-2xl border p-5 shadow-sm">
            <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#0E1B2C] text-[#C8E64A]">
                <Icon className="size-6" aria-hidden />
            </span>
            <div>
                <p className="text-muted-foreground text-sm">{label}</p>
                <p className="text-2xl font-extrabold">{value}</p>
            </div>
        </div>
    );
}

type Rule = {
    id: number;
    name: string;
    sport: string;
    start: string;
    end: string;
    price_cents: number;
    vat_rate: number;
    valid_from: string;
    valid_until: string | null;
    upcoming: boolean;
};

// F24 : heures d'ouverture et durée des créneaux.
function Settings({ settings }: { settings: { opens_at: string; closes_at: string; slot_minutes: number } }) {
    const t = useT();
    const form = useForm({ ...settings, slot_minutes: String(settings.slot_minutes) });
    const field = 'bg-card mt-1 block w-full rounded-md border px-2 py-1.5';
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put('/admin/settings', { preserveScroll: true });
            }}
            className="grid gap-2 text-sm sm:grid-cols-4"
        >
            <label>
                {t('Ouverture')}
                <input type="time" required value={form.data.opens_at} onChange={(e) => form.setData('opens_at', e.target.value)} className={field} />
            </label>
            <label>
                {t('Fermeture')}
                <input
                    type="time"
                    required
                    value={form.data.closes_at}
                    onChange={(e) => form.setData('closes_at', e.target.value)}
                    className={field}
                />
            </label>
            <label>
                {/* Le foot à 5 garde des créneaux d'une heure : ce réglage ne vaut que pour le padel. */}
                {t('Durée d’une partie de padel')}
                <select value={form.data.slot_minutes} onChange={(e) => form.setData('slot_minutes', e.target.value)} className={field}>
                    <option value="60">{t(':n minutes', { n: 60 })}</option>
                    <option value="90">{t(':n minutes', { n: 90 })}</option>
                    <option value="120">{t(':n minutes', { n: 120 })}</option>
                </select>
            </label>
            <div className="flex items-end">
                <Button type="submit" variant="outline" disabled={form.processing} className="w-full">
                    {t('Enregistrer')}
                </Button>
            </div>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-[#9E2833] sm:col-span-4">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}

// F24 : nouveau tarif, tout de suite ou à une date future.
function NewRule() {
    const t = useT();
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({ name: '', sport: 'padel', start_time: '09:00', end_time: '17:00', price: '', vat_rate: '6', valid_from: today });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, price_cents: Math.round(parseFloat(d.price.replace(',', '.')) * 100) }));
        form.post('/admin/pricing-rules', { preserveScroll: true, onSuccess: () => form.reset('name', 'price') });
    };
    const field = 'bg-card mt-1 block w-full rounded-md border px-2 py-1.5';
    return (
        <form onSubmit={submit} className="mt-4 grid gap-2 border-t pt-4 text-sm sm:grid-cols-3">
            <p className="font-semibold sm:col-span-3">{t('Nouveau tarif')}</p>
            <label className="sm:col-span-2">
                {t('Nom')}
                <input
                    required
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    className={field}
                    placeholder={t('Heures creuses hiver')}
                />
            </label>
            <label>
                {t('Discipline')}
                <select value={form.data.sport} onChange={(e) => form.setData('sport', e.target.value)} className={field}>
                    {Object.entries(SPORT_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>
                            {t(label)}
                        </option>
                    ))}
                </select>
            </label>
            <label>
                {t('De')}
                <input
                    type="time"
                    required
                    value={form.data.start_time}
                    onChange={(e) => form.setData('start_time', e.target.value)}
                    className={field}
                />
            </label>
            <label>
                {t('À')}
                <input type="time" required value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} className={field} />
            </label>
            <label>
                {t('Prix TTC (€)')}
                <input
                    required
                    inputMode="decimal"
                    value={form.data.price}
                    onChange={(e) => form.setData('price', e.target.value)}
                    className={field}
                />
            </label>
            <label>
                {t('TVA')}
                <select value={form.data.vat_rate} onChange={(e) => form.setData('vat_rate', e.target.value)} className={field}>
                    <option value="6">{t('6 % (accès au terrain)')}</option>
                    <option value="21">21 %</option>
                </select>
            </label>
            <label>
                {t('À partir du')}
                <input
                    type="date"
                    required
                    min={today}
                    value={form.data.valid_from}
                    onChange={(e) => form.setData('valid_from', e.target.value)}
                    className={field}
                />
            </label>
            <div className="flex items-end">
                <Button type="submit" disabled={form.processing} className="w-full">
                    {t('Ajouter')}
                </Button>
            </div>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-[#9E2833] sm:col-span-3">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}
type Closure = { id: number; court: string; reason: string; from: string; to: string };

function PriceRow({ rule }: { rule: Rule }) {
    const t = useT();
    const [value, setValue] = useState((rule.price_cents / 100).toFixed(2));
    const save = (e: React.FormEvent) => {
        e.preventDefault();
        router.patch(
            `/admin/pricing-rules/${rule.id}`,
            { price_cents: Math.round(parseFloat(value.replace(',', '.')) * 100) },
            { preserveScroll: true },
        );
    };
    return (
        <form onSubmit={save} className="flex flex-wrap items-center justify-between gap-2 border-b py-2 last:border-0">
            <label htmlFor={`rule-${rule.id}`} className="text-sm">
                <span className="font-semibold">{rule.name}</span> · {rule.start} – {rule.end} · {t('TVA :rate %', { rate: rule.vat_rate })}
                <span className="text-muted-foreground block text-xs">
                    {rule.upcoming ? t('À partir du :date', { date: rule.valid_from }) : t('Depuis le :date', { date: rule.valid_from })}
                    {rule.valid_until ? ` · ${t('jusqu’au :date', { date: rule.valid_until })}` : ''}
                </span>
            </label>
            <span className="flex items-center gap-2">
                <input
                    id={`rule-${rule.id}`}
                    inputMode="decimal"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    className="bg-card w-24 rounded-md border px-2 py-1 text-right"
                />
                <span className="text-sm">€</span>
                <Button type="submit" variant="outline" size="sm">
                    {t('Enregistrer')}
                </Button>
                {!rule.valid_until && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            const until = prompt(t('Dernier jour de ce tarif (AAAA-MM-JJ) :'), new Date().toISOString().slice(0, 10));
                            if (until) router.post(`/admin/pricing-rules/${rule.id}/close`, { valid_until: until }, { preserveScroll: true });
                        }}
                    >
                        {t('Clôturer')}
                    </Button>
                )}
            </span>
        </form>
    );
}

export default function Dashboard({
    stats,
    day,
    today,
    users,
    search,
    courts,
    minutes,
    players,
    rules,
    settings,
    closures,
    flags,
}: {
    stats: Stats;
    day: string;
    today: Row[];
    users: Page<Account>;
    search: string;
    courts: { id: number; name: string; sport: string }[];
    minutes: Record<string, number>;
    players: Record<string, number>;
    rules: Rule[];
    settings: { opens_at: string; closes_at: string; slot_minutes: number };
    closures: Closure[];
    flags: Flag[];
}) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const closure = useForm({ court_id: courts[0]?.id ?? 0, from: '', to: '', reason: '' });
    const [q, setQ] = useState(search);
    const find = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin', q ? { q } : {}, { preserveScroll: true, preserveState: true });
    };

    const showDay = (value: string) => {
        router.get('/admin', { ...(search ? { q: search } : {}), ...(value ? { day: value } : {}) }, { preserveScroll: true });
    };
    // Annulation par le club : tout est remboursé, le motif part chez les joueurs.
    const cancelBooking = (b: Row) => {
        const reason = prompt(
            t('Annuler la réservation de :player (:court, :time) ?\nTout est remboursé. Motif envoyé aux joueurs, dix caractères au moins :', {
                player: b.player,
                court: b.court,
                time: b.time,
            }),
        );
        if (reason) router.post(`/admin/bookings/${b.id}/cancel`, { reason }, { preserveScroll: true });
    };

    const reactivate = (u: Account) => {
        if (confirm(t('Réactiver le compte de :name ?', { name: u.name })))
            router.post(`/admin/users/${u.id}/reactivate`, {}, { preserveScroll: true });
    };
    // F28 : suspension et révocation toujours motivées ; le motif part chez le joueur.
    const [target, setTarget] = useState<{ user: Account; action: 'suspend' | 'revoke' } | null>(null);
    const decision = useForm({ reason: '', days: '7' });
    const confirmDecision = (e: React.FormEvent) => {
        e.preventDefault();
        if (!target) return;
        decision.transform((d) =>
            target.action === 'suspend' ? { reason: d.reason, days: d.days === '' ? null : Number(d.days) } : { reason: d.reason },
        );
        decision.post(`/admin/users/${target.user.id}/${target.action}`, {
            preserveScroll: true,
            onSuccess: () => {
                setTarget(null);
                decision.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('Tableau de bord'), href: '/admin' }]}>
            <Head title={t('Tableau de bord')} />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow={t('Espace gérant')} title={t('Tableau de bord')} />

                <section aria-label={t('Chiffres du jour')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat icon={CalendarCheck} label={t('Réservations aujourd’hui')} value={String(stats.bookings_today)} />
                    <Stat icon={Gauge} label={t('Occupation des terrains')} value={`${stats.occupancy} %`} />
                    <Stat icon={Wallet} label={t('Encaissé aujourd’hui (TTC)')} value={euros(stats.revenue_today_cents)} />
                    <Stat icon={TrendingUp} label={t('Encaissé ce mois (TTC)')} value={euros(stats.revenue_month_cents)} />
                </section>

                {flags.length > 0 && (
                    <section aria-labelledby="signalements" className="flex flex-col gap-2 rounded-2xl border border-[#CC3342]/30 p-4">
                        <h2 id="signalements" className="text-lg font-semibold">
                            {t('Signalements à examiner')}
                        </h2>
                        <p className="text-sm text-[#5F6B78]">
                            {t(
                                'Le système signale, il ne sanctionne jamais seul. Vérifiez, puis classez le signalement ; une suspension se décide dans la liste des comptes.',
                            )}
                        </p>
                        <ul className="flex flex-col gap-2">
                            {flags.map((f) => (
                                <li key={f.id} className="bg-card flex flex-wrap items-start justify-between gap-3 rounded-xl border p-3 text-sm">
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold">
                                            {t(FLAG_LABELS[f.type] ?? f.type)} · {f.user.name} ({f.user.email})
                                        </p>
                                        {f.details && <p className="text-[#5F6B78]">{f.details}</p>}
                                        <p className="text-xs text-[#6B7480]">{f.created_at}</p>
                                    </div>
                                    <div className="flex gap-3">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(`/admin/flags/${f.id}/review`, { status: 'dismissed' }, { preserveScroll: true })
                                            }
                                            className="font-semibold underline"
                                        >
                                            {t('Classer sans suite')}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(`/admin/flags/${f.id}/review`, { status: 'actioned' }, { preserveScroll: true })
                                            }
                                            className="font-semibold text-[#9E2833] underline"
                                        >
                                            {t('Mesure prise')}
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">{t('Réservations du jour')}</h2>
                        <label className="flex items-center gap-2 text-sm">
                            {t('Jour affiché')}
                            <input
                                type="date"
                                value={day}
                                onChange={(e) => showDay(e.target.value)}
                                className="bg-background rounded-md border px-2 py-1"
                            />
                        </label>
                    </div>
                    {(errors.booking || errors.reason) && (
                        <p role="alert" className="text-sm text-[#9E2833]">
                            {errors.booking ?? errors.reason}
                        </p>
                    )}
                    {today.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('Aucune réservation ce jour-là.')}</p>
                    ) : (
                        <div className="bg-card relative overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-2">{t('Heure')}</th>
                                        <th className="p-2">{t('Terrain')}</th>
                                        <th className="p-2">{t('Joueur')}</th>
                                        <th className="p-2">{t('État')}</th>
                                        <th className="p-2">
                                            <span className="sr-only">{t('Action')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {today.map((b) => (
                                        <tr key={b.id} className="border-b last:border-0">
                                            <td className="p-2 whitespace-nowrap">{b.time}</td>
                                            <td className="p-2">{b.court}</td>
                                            <td className="p-2">{b.player}</td>
                                            <td className="p-2">
                                                <StatusBadge status={b.status} />
                                            </td>
                                            <td className="p-2 text-right">
                                                {b.cancellable && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="border-[#CC3342]/40 text-[#9E2833] hover:bg-[#FCE6E8]"
                                                        onClick={() => cancelBooking(b)}
                                                    >
                                                        {t('Annuler et rembourser')}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">
                            {t('Comptes')} <span className="text-muted-foreground text-sm font-normal">({users.total})</span>
                        </h2>
                        <form onSubmit={find} role="search" className="flex gap-2">
                            <label htmlFor="q" className="sr-only">
                                {t('Rechercher un compte')}
                            </label>
                            <input
                                id="q"
                                type="search"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder={t('Nom ou e-mail')}
                                className="bg-card rounded-md border px-3 py-1.5 text-sm"
                            />
                            <Button type="submit" variant="outline" size="sm">
                                {t('Rechercher')}
                            </Button>
                        </form>
                    </div>
                    <div className="bg-card relative overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="p-2">{t('Nom')}</th>
                                    <th className="p-2">{t('E-mail')}</th>
                                    <th className="p-2">{t('E-mail confirmé')}</th>
                                    <th className="p-2">{t('Rôle')}</th>
                                    <th className="p-2">{t('Statut')}</th>
                                    <th className="p-2">{t('Inscrit le')}</th>
                                    <th className="p-2">
                                        <span className="sr-only">{t('Action')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-muted-foreground p-3 text-center">
                                            {t('Aucun compte ne correspond à « :search ».', { search })}
                                        </td>
                                    </tr>
                                )}
                                {users.data.map((u) => {
                                    const [label, style] = ACCOUNT[u.status] ?? [u.status, 'bg-muted text-muted-foreground'];
                                    return (
                                        <tr key={u.id} className="border-b last:border-0">
                                            <td className="p-2">{u.name}</td>
                                            <td className="p-2">{u.email}</td>
                                            <td className="p-2">
                                                <span
                                                    className={`${pill} ${u.verified ? 'bg-[#EEF4D6] text-[#4E6600]' : 'bg-[#ECEAE3] text-[#6B7480]'}`}
                                                >
                                                    {u.verified ? t('Confirmé') : t('Non confirmé')}
                                                </span>
                                            </td>
                                            <td className="p-2">{t(ROLES[u.role] ?? u.role)}</td>
                                            <td className="p-2">
                                                <span className={`${pill} ${style}`}>{t(label)}</span>
                                            </td>
                                            <td className="p-2 whitespace-nowrap">{u.created_at}</td>
                                            <td className="p-2 text-right">
                                                {u.role === 'player' && u.status === 'suspended' && (
                                                    <Button variant="outline" size="sm" onClick={() => reactivate(u)}>
                                                        {t('Réactiver')}
                                                    </Button>
                                                )}
                                                {u.role === 'player' && u.status === 'active' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="border-[#CC3342]/40 text-[#9E2833] hover:bg-[#FCE6E8]"
                                                        onClick={() => setTarget({ user: u, action: 'suspend' })}
                                                    >
                                                        {t('Suspendre')}
                                                    </Button>
                                                )}
                                                {u.role === 'player' && ['active', 'suspended'].includes(u.status) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="ml-1 text-[#9E2833]"
                                                        onClick={() => setTarget({ user: u, action: 'revoke' })}
                                                    >
                                                        {t('Révoquer')}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {target && (
                        <form
                            onSubmit={confirmDecision}
                            className="flex flex-col gap-3 rounded-xl border border-[#CC3342]/40 bg-[#FFF7F7] p-4 text-sm"
                        >
                            <p className="font-semibold">
                                {target.action === 'suspend'
                                    ? t('Suspendre le compte de :name', { name: target.user.name })
                                    : t('Révoquer définitivement le compte de :name', { name: target.user.name })}
                            </p>
                            <label className="flex flex-col gap-1">
                                {t('Motif (envoyé au joueur)')}
                                <textarea
                                    required
                                    minLength={10}
                                    rows={3}
                                    value={decision.data.reason}
                                    onChange={(e) => decision.setData('reason', e.target.value)}
                                    className="rounded-lg border border-[#DAD7CC] bg-white p-2"
                                />
                            </label>
                            {target.action === 'suspend' && (
                                <label className="flex flex-col gap-1">
                                    {t('Durée')}
                                    <select
                                        value={decision.data.days}
                                        onChange={(e) => decision.setData('days', e.target.value)}
                                        className="h-9 w-56 rounded-md border border-[#DAD7CC] bg-white px-2"
                                    >
                                        {[1, 3, 7, 14, 30, 90].map((d) => (
                                            <option key={d} value={d}>
                                                {d > 1 ? t(':n jours', { n: d }) : t(':n jour', { n: d })}
                                            </option>
                                        ))}
                                        <option value="">{t('Jusqu’à nouvelle décision')}</option>
                                    </select>
                                </label>
                            )}
                            {decision.errors.reason && (
                                <p role="alert" className="text-[#9E2833]">
                                    {decision.errors.reason}
                                </p>
                            )}
                            <div className="flex gap-2">
                                <Button type="submit" disabled={decision.processing} className="bg-[#9E2833] text-white hover:bg-[#82202A]">
                                    {target.action === 'suspend' ? t('Suspendre') : t('Révoquer')}
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setTarget(null)}>
                                    {t('Annuler')}
                                </Button>
                            </div>
                        </form>
                    )}
                    {users.last_page > 1 && (
                        <nav aria-label={t('Pages des comptes')} className="flex items-center justify-end gap-3 text-sm">
                            {users.prev_page_url ? (
                                <Link href={users.prev_page_url} preserveScroll className="underline">
                                    ‹ {t('Précédent')}
                                </Link>
                            ) : (
                                <span className="text-muted-foreground">‹ {t('Précédent')}</span>
                            )}
                            <span>{t('Page :current sur :last', { current: users.current_page, last: users.last_page })}</span>
                            {users.next_page_url ? (
                                <Link href={users.next_page_url} preserveScroll className="underline">
                                    {t('Suivant')} ›
                                </Link>
                            ) : (
                                <span className="text-muted-foreground">{t('Suivant')} ›</span>
                            )}
                        </nav>
                    )}
                </section>

                <section aria-labelledby="disciplines" className="flex flex-col gap-2">
                    <h2 id="disciplines" className="text-lg font-semibold">
                        {t('Terrains et tarifs par discipline')}
                    </h2>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <div className="bg-card rounded-2xl border p-5">
                            <h3 className="font-semibold">{t('Terrains')}</h3>
                            <p className="text-muted-foreground mb-2 text-sm">
                                {t('Chaque terrain a sa discipline. La discipline fixe la durée du créneau et le nombre de joueurs.')}
                            </p>
                            <div className="relative overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="p-2">{t('Terrain')}</th>
                                            <th className="p-2">{t('Discipline')}</th>
                                            <th className="p-2">{t('Créneau')}</th>
                                            <th className="p-2">{t('Joueurs')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {courts.map((c) => (
                                            <tr key={c.id} className="border-b last:border-0">
                                                <td className="p-2">{c.name}</td>
                                                <td className="p-2">
                                                    <span className={`${pill} ${SPORT_PILL[c.sport]}`}>{t(SPORT_LABELS[c.sport])}</span>
                                                </td>
                                                <td className="p-2 whitespace-nowrap">{t(':n min', { n: minutes[c.sport] })}</td>
                                                <td className="p-2">{players[c.sport]}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="bg-card rounded-2xl border p-5">
                            <h3 className="font-semibold">{t('Tarifs par discipline')}</h3>
                            <p className="text-muted-foreground mb-2 text-sm">
                                {t('Prix TTC par créneau. Les réservations déjà payées gardent leur prix.')}
                            </p>
                            {Object.keys(SPORT_LABELS)
                                .filter((s) => rules.some((r) => r.sport === s))
                                .map((s) => (
                                    <div key={s} className="mt-3">
                                        <h4 className="text-sm font-semibold">
                                            {t(SPORT_LABELS[s])} · {t(':n min', { n: minutes[s] })}
                                        </h4>
                                        {rules
                                            .filter((r) => r.sport === s)
                                            .map((r) => (
                                                <PriceRow key={r.id} rule={r} />
                                            ))}
                                    </div>
                                ))}
                            <NewRule />
                            {(errors.pricing || errors.valid_until) && (
                                <p role="alert" className="mt-2 text-sm text-[#9E2833]">
                                    {errors.pricing ?? errors.valid_until}
                                </p>
                            )}
                            {errors.price_cents && (
                                <p role="alert" className="mt-2 text-sm text-[#9E2833]">
                                    {errors.price_cents}
                                </p>
                            )}
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    <div className="bg-card rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">{t('Horaires')}</h2>
                        <p className="text-muted-foreground mb-2 text-sm">{t('Les réservations déjà faites gardent leurs heures.')}</p>
                        <Settings settings={settings} />
                    </div>

                    <div className="bg-card rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">{t('Fermer un terrain')}</h2>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                closure.post('/admin/closures', { preserveScroll: true, onSuccess: () => closure.reset('from', 'to', 'reason') });
                            }}
                            className="mt-2 grid gap-2 sm:grid-cols-2"
                        >
                            <label className="text-sm">
                                {t('Terrain')}
                                <select
                                    value={closure.data.court_id}
                                    onChange={(e) => closure.setData('court_id', Number(e.target.value))}
                                    className="bg-card mt-1 block w-full rounded-md border px-2 py-1.5"
                                >
                                    {courts.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name} · {t(SPORT_LABELS[c.sport])}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm">
                                {t('Motif')}
                                <input
                                    value={closure.data.reason}
                                    onChange={(e) => closure.setData('reason', e.target.value)}
                                    placeholder={t('Réparation du gazon')}
                                    className="bg-card mt-1 block w-full rounded-md border px-2 py-1.5"
                                />
                            </label>
                            <label className="text-sm">
                                {t('Du')}
                                <input
                                    type="datetime-local"
                                    value={closure.data.from}
                                    onChange={(e) => closure.setData('from', e.target.value)}
                                    className="bg-card mt-1 block w-full rounded-md border px-2 py-1.5"
                                />
                            </label>
                            <label className="text-sm">
                                {t('Au')}
                                <input
                                    type="datetime-local"
                                    value={closure.data.to}
                                    onChange={(e) => closure.setData('to', e.target.value)}
                                    className="bg-card mt-1 block w-full rounded-md border px-2 py-1.5"
                                />
                            </label>
                            {(errors.closure || Object.keys(closure.errors).length > 0) && (
                                <p role="alert" className="text-sm text-[#9E2833] sm:col-span-2">
                                    {errors.closure ?? t('Vérifiez le terrain, les dates et le motif.')}
                                </p>
                            )}
                            <Button type="submit" disabled={closure.processing} className="sm:col-span-2">
                                {t('Fermer ce terrain')}
                            </Button>
                        </form>
                        {closures.length > 0 && (
                            <ul className="mt-4 space-y-2 text-sm">
                                {closures.map((c) => (
                                    <li
                                        key={c.id}
                                        className="flex items-center justify-between gap-2 rounded-lg bg-[#FCE6E8] px-3 py-2 text-[#9E2833]"
                                    >
                                        <span>
                                            <strong>{c.court}</strong> · {c.from} → {c.to} · {c.reason}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => router.delete(`/admin/closures/${c.id}`, { preserveScroll: true })}
                                            className="font-semibold underline"
                                        >
                                            {t('Rouvrir')}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
