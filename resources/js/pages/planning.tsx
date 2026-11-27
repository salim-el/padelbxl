import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { SPORT_LABELS, euros, ucfirst } from '@/lib/money';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

type Cell = { court_id: number; available: boolean; price_cents: number | null; past: boolean; off_peak: boolean; flash: boolean; closed: boolean };
type Row = { time: string; end: string; starts_at: string; cells: Cell[] };
type Court = { id: number; name: string };
type Moving = { id: string; court: string; when: string };
type Props = {
    date: string;
    today: string;
    max: string;
    courts: Court[];
    rows: Row[];
    moving: Moving | null;
    sport: string;
    sports: string[];
    sport_chosen: boolean;
    minutes: Record<string, number>;
    players: Record<string, number>;
    from_prices: Record<string, number | null>;
};

const NAV =
    'inline-flex size-9 items-center justify-center rounded-lg border border-[#F7F6F1]/30 text-[#F7F6F1] transition hover:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent';
// Onglet de sport dans le bandeau marine : l'actif prend le vert citron des boutons du site.
// L'anneau de focus est détaché par un liseré marine : sinon il se confond avec l'onglet actif, du même vert.
const TAB =
    'rounded-full border px-3 py-1 text-sm font-semibold transition focus-visible:ring-2 focus-visible:ring-[#C8E64A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0E1B2C] focus-visible:outline-none';
// Textes des boutons de l'écran de choix, repris de la maquette.
const CHOICE: Record<string, string> = { padel: 'Voir les créneaux padel', foot5: 'Voir les créneaux foot' };

export default function Planning({ date, today, max, courts, rows, moving, sport, sports, sport_chosen, minutes, players, from_prices }: Props) {
    const t = useT();
    const { auth, errors, locale } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const label = new Date(`${date}T12:00:00`).toLocaleDateString(`${locale}-BE`, { weekday: 'long', day: 'numeric', month: 'long' });

    // Changer de jour garde le sport et, en déplacement (F14), la réservation à déplacer.
    const goTo = (d: string) =>
        router.get('/planning', { date: d, sport, ...(moving ? { move: moving.id } : {}) }, { preserveScroll: true, preserveState: true });
    // Décalage en jours sur la date locale, sans passer par le fuseau UTC.
    const shift = (n: number) => {
        const [y, m, d] = date.split('-').map(Number);
        const t = new Date(y, m - 1, d + n);
        return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
    };

    const book = (cell: Cell, row: Row) => {
        if (!auth.user) return router.visit('/login');
        const slot = { court_id: cell.court_id, starts_at: row.starts_at };
        if (moving) return router.post(`/bookings/${moving.id}/move`, slot);
        router.post('/bookings', slot);
    };

    const slotClass = (cell: Cell) => {
        if (cell.flash) return 'border-[#CC3342]/40 bg-[#FCE6E8] text-[#9E2833] hover:bg-[#f8d5d9]';
        if (cell.off_peak) return 'border-[#1D5C7A]/25 bg-[#E3F0F7] text-[#1D5C7A] hover:bg-[#cfe6f2]';
        return 'border-[#DAD7CC] bg-white text-[#0E1B2C] hover:border-[#C8E64A] hover:bg-[#EEF4D6]';
    };

    // Écran de choix : padel ou foot à 5, avant le planning du sport choisi.
    if (!sport_chosen) {
        return (
            <AppLayout breadcrumbs={[{ title: t('Planning'), href: '/planning' }]}>
                <Head title={t('Planning')} />
                <div className="flex flex-col gap-5 p-4">
                    <PageHeader eyebrow={t('Arena Padel Club · Anderlecht')} title={t('Que voulez-vous réserver ?')}>
                        <span className="rounded-lg border border-[#F7F6F1]/30 px-3 py-1.5 text-sm">{ucfirst(label)}</span>
                    </PageHeader>
                    <div className="grid gap-4 md:grid-cols-2">
                        {sports.map((s) => (
                            <article key={s} className="bg-card flex flex-col overflow-hidden rounded-2xl border shadow-sm">
                                <h2 className="bg-[#0E1B2C] px-6 py-5 text-2xl font-extrabold text-[#F7F6F1] uppercase">{t(SPORT_LABELS[s])}</h2>
                                <div className="flex flex-1 flex-col gap-4 p-6">
                                    <ul className="list-disc space-y-1 pl-5 marker:text-[#1D5C7A]">
                                        <li>{t(':n joueurs par terrain', { n: players[s] })}</li>
                                        <li>{t('Créneaux de :n minutes', { n: minutes[s] })}</li>
                                        <li>{t('Partage du prix entre :n', { n: players[s] })}</li>
                                    </ul>
                                    {from_prices[s] != null && (
                                        <p className="text-muted-foreground">
                                            {t('À partir de')}{' '}
                                            <span className="text-foreground text-2xl font-extrabold">{euros(from_prices[s])}</span>{' '}
                                            {t('par terrain')}
                                        </p>
                                    )}
                                    <Link
                                        href={`/planning?sport=${s}&date=${date}`}
                                        className="mt-auto self-start rounded-lg bg-[#C8E64A] px-5 py-2.5 font-bold text-[#0E1B2C] transition hover:bg-[#b5d23c] focus-visible:ring-2 focus-visible:ring-[#1D5C7A] focus-visible:ring-offset-2 focus-visible:outline-none"
                                    >
                                        {t(CHOICE[s])}
                                    </Link>
                                </div>
                            </article>
                        ))}
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {t(
                            'Un seul compte pour les deux sports. Le choix reste affiché en haut du planning : on peut passer de l’un à l’autre à tout moment.',
                        )}
                    </p>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={[{ title: t('Planning'), href: '/planning' }]}>
            <Head title={t('Planning')} />
            <div className="flex flex-col gap-5 p-4">
                <PageHeader
                    eyebrow={t(moving ? 'Déplacer une réservation' : 'Réserver un terrain')}
                    title={t(moving ? 'Choisissez le nouveau créneau' : 'Choisissez un créneau')}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        {/* Une réservation garde son sport : pas d'onglets pendant un déplacement. */}
                        {sports.length > 1 && !moving && (
                            <div role="group" aria-label={t('Sport')} className="flex flex-wrap gap-2">
                                {sports.map((s) => (
                                    <button
                                        key={s}
                                        type="button"
                                        aria-pressed={s === sport}
                                        // preserveState garde la page montée : le focus clavier reste sur l'onglet choisi.
                                        onClick={() => router.get('/planning', { date, sport: s }, { preserveScroll: true, preserveState: true })}
                                        className={`${TAB} ${s === sport ? 'border-[#C8E64A] bg-[#C8E64A] text-[#0E1B2C]' : 'border-[#F7F6F1]/30 text-[#F7F6F1] hover:bg-white/10'}`}
                                    >
                                        {t(SPORT_LABELS[s])} · {t(':n min', { n: minutes[s] })}
                                    </button>
                                ))}
                            </div>
                        )}
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                className={NAV}
                                disabled={date <= today}
                                onClick={() => goTo(shift(-1))}
                                aria-label={t('Jour précédent')}
                            >
                                <ChevronLeft className="size-5" />
                            </button>
                            <label className="sr-only" htmlFor="date">
                                {t('Date')}
                            </label>
                            <input
                                id="date"
                                type="date"
                                min={today}
                                max={max}
                                value={date}
                                className="rounded-lg border border-[#F7F6F1]/30 bg-[#17304A] px-3 py-1.5 text-[#F7F6F1] [color-scheme:dark]"
                                onChange={(e) => /^20\d{2}-\d{2}-\d{2}$/.test(e.target.value) && goTo(e.target.value)}
                            />
                            <button
                                type="button"
                                className={NAV}
                                disabled={date >= max}
                                onClick={() => goTo(shift(1))}
                                aria-label={t('Jour suivant')}
                            >
                                <ChevronRight className="size-5" />
                            </button>
                        </div>
                    </div>
                </PageHeader>

                {moving && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#C8E64A] bg-[#F4F9E1] p-4 text-sm text-[#0E1B2C]">
                        <p>
                            {t('Réservation actuelle : :court, :when. La différence de prix est payée ou remboursée.', {
                                court: moving.court,
                                when: moving.when,
                            })}
                        </p>
                        <Link href={`/bookings/${moving.id}`} className="font-semibold underline">
                            {t('Garder ce créneau')}
                        </Link>
                    </div>
                )}
                {errors.date && (
                    <p role="alert" className="rounded-lg border border-[#9E2833]/30 bg-[#FCE6E8] p-3 text-sm text-[#9E2833]">
                        {t('Choisissez une date entre aujourd’hui et le :date.', { date: max.split('-').reverse().join('/') })}
                    </p>
                )}
                {errors.booking && (
                    <p role="alert" className="rounded-lg border border-[#9E2833]/30 bg-[#FCE6E8] p-3 text-sm text-[#9E2833]">
                        {errors.booking}
                    </p>
                )}

                <section className="bg-card overflow-hidden rounded-2xl border shadow-sm">
                    <h2 className="border-b px-4 py-3 font-semibold">
                        {ucfirst(label)} · {t(SPORT_LABELS[sport])} · {t(':n joueurs par terrain', { n: players[sport] })}
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] table-fixed border-separate border-spacing-1.5 text-sm">
                            <thead>
                                <tr>
                                    <th className="w-20 px-2 py-1 text-left align-bottom text-xs font-semibold tracking-wide text-[#5F6B78] uppercase">
                                        {t('Créneau')}
                                    </th>
                                    {courts.map((c) => (
                                        <th key={c.id} className="px-2 py-1 text-left align-bottom">
                                            <span className="font-bold">{c.name}</span>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.time}>
                                        <td className="px-2 font-mono text-xs font-semibold whitespace-nowrap text-[#17304A] dark:text-[#F7F6F1]">
                                            {row.time}
                                            <span className="block text-[#5F6B78]">{row.end}</span>
                                        </td>
                                        {row.cells.map((cell) => (
                                            <td key={cell.court_id}>
                                                {cell.available ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => book(cell, row)}
                                                        className={`flex h-14 w-full flex-col items-center justify-center rounded-lg border px-2 text-center font-semibold transition focus-visible:ring-2 focus-visible:ring-[#1D5C7A] focus-visible:outline-none ${slotClass(cell)}`}
                                                    >
                                                        {euros(cell.price_cents)}
                                                        {cell.flash ? (
                                                            <span className="block text-[11px] font-bold">{t('flash −20 %')}</span>
                                                        ) : (
                                                            cell.off_peak && (
                                                                <span className="block text-[11px] font-normal opacity-80">{t('heure creuse')}</span>
                                                            )
                                                        )}
                                                    </button>
                                                ) : (
                                                    <span className="flex h-14 items-center justify-center rounded-lg border border-dashed border-[#DAD7CC] px-2 text-center text-xs text-[#6B7480]">
                                                        {t(cell.past ? 'Passé' : cell.closed ? 'Fermé' : 'Complet')}
                                                    </span>
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-[#5F6B78]">
                    <span className="flex items-center gap-2">
                        <span className="size-3 rounded-sm border border-[#DAD7CC] bg-white" aria-hidden /> {t('Heure pleine')}
                    </span>
                    <span className="flex items-center gap-2">
                        <span className="size-3 rounded-sm bg-[#E3F0F7]" aria-hidden /> {t('Heure creuse')}
                    </span>
                    <span className="flex items-center gap-2">
                        <span className="size-3 rounded-sm bg-[#FCE6E8] ring-1 ring-[#CC3342]/40" aria-hidden />{' '}
                        {t('Flash : −20 % à moins de 3 h du début')}
                    </span>
                    <span>
                        {t('Prix TTC par créneau de :n minutes, TVA de 6 % comprise. Annulation remboursée jusqu’à 24 h avant.', {
                            n: minutes[sport],
                        })}
                    </span>
                </div>
                {!auth.user && (
                    <p className="text-sm">
                        <Link href="/login" className="font-semibold underline">
                            {t('Connectez-vous')}
                        </Link>{' '}
                        {t('pour réserver.')}
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
