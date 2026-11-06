import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { euros, ucfirst } from '@/lib/money';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

type Cell = { court_id: number; available: boolean; price_cents: number | null; past: boolean; off_peak: boolean; flash: boolean; closed: boolean };
type Row = { time: string; end: string; starts_at: string; cells: Cell[] };
type Court = { id: number; name: string; is_arena: boolean };
type Props = { date: string; today: string; max: string; courts: Court[]; rows: Row[]; arcade_supplement_cents: number | null };

const NAV =
    'inline-flex size-9 items-center justify-center rounded-lg border border-[#F7F6F1]/30 text-[#F7F6F1] transition hover:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent';

export default function Planning({ date, today, max, courts, rows, arcade_supplement_cents }: Props) {
    const t = useT();
    const { auth, errors, locale } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const label = new Date(`${date}T12:00:00`).toLocaleDateString(`${locale}-BE`, { weekday: 'long', day: 'numeric', month: 'long' });

    const goTo = (d: string) => router.get('/planning', { date: d }, { preserveScroll: true, preserveState: true });
    // Décalage en jours sur la date locale, sans passer par le fuseau UTC.
    const shift = (n: number) => {
        const [y, m, d] = date.split('-').map(Number);
        const t = new Date(y, m - 1, d + n);
        return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
    };

    const book = (cell: Cell, row: Row) => {
        if (!auth.user) return router.visit('/login');
        router.post('/bookings', { court_id: cell.court_id, starts_at: row.starts_at });
    };

    const slotClass = (cell: Cell) => {
        if (cell.flash) return 'border-[#CC3342]/40 bg-[#FCE6E8] text-[#9E2833] hover:bg-[#f8d5d9]';
        if (cell.off_peak) return 'border-[#1D5C7A]/25 bg-[#E3F0F7] text-[#1D5C7A] hover:bg-[#cfe6f2]';
        return 'border-[#DAD7CC] bg-white text-[#0E1B2C] hover:border-[#C8E64A] hover:bg-[#EEF4D6]';
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('Planning'), href: '/planning' }]}>
            <Head title={t('Planning')} />
            <div className="flex flex-col gap-5 p-4">
                <PageHeader eyebrow={t('Réserver un terrain')} title={t('Choisissez un créneau')}>
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
                        <button type="button" className={NAV} disabled={date >= max} onClick={() => goTo(shift(1))} aria-label={t('Jour suivant')}>
                            <ChevronRight className="size-5" />
                        </button>
                    </div>
                </PageHeader>

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
                    <h2 className="border-b px-4 py-3 font-semibold">{ucfirst(label)}</h2>
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
                    <span className="flex items-center gap-2">
                        <span className="size-3 rounded-sm bg-[#0E1B2C] ring-1 ring-[#C8E64A]" aria-hidden />{' '}
                        {t('Mode arcade sur tous les terrains : +:price au paiement', { price: euros(arcade_supplement_cents) })}
                    </span>
                    <span>{t('Prix TTC par créneau de 90 minutes, TVA de 6 % comprise. Annulation remboursée jusqu’à 24 h avant.')}</span>
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
