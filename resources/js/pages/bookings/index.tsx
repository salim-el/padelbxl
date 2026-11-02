import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { euros, shortMonth, ucfirst } from '@/lib/money';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Ticket } from 'lucide-react';

type Booking = { id: string; court: string; is_arena: boolean; date: string; time: string; status: string; price_cents: number };

export default function Index({ bookings }: { bookings: Booking[] }) {
    const t = useT();
    return (
        <AppLayout breadcrumbs={[{ title: t('Mes réservations'), href: '/bookings' }]}>
            <Head title={t('Mes réservations')} />
            <div className="flex flex-col gap-5 p-4">
                <PageHeader eyebrow={t('Mon espace')} title={t('Mes réservations')}>
                    <Link
                        href="/planning"
                        className="rounded-lg bg-[#C8E64A] px-4 py-2 text-sm font-bold text-[#0E1B2C] transition hover:bg-[#b5d23c]"
                    >
                        {t('Réserver un terrain')}
                    </Link>
                </PageHeader>

                {bookings.length === 0 ? (
                    <div className="bg-card flex flex-col items-center gap-3 rounded-2xl border border-dashed p-10 text-center">
                        <Ticket className="size-10 text-[#1D5C7A]" aria-hidden />
                        <p className="font-semibold">{t('Aucune réservation pour l’instant.')}</p>
                        <Link href="/planning" className="text-sm font-semibold underline">
                            {t('Voir les créneaux libres')}
                        </Link>
                    </div>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2">
                        {bookings.map((b) => {
                            // « samedi 24 octobre 2026 » : on garde le jour et le mois pour la pastille.
                            const [, day, month] = b.date.split(' ');
                            return (
                                <li key={b.id}>
                                    <Link
                                        href={b.status === 'pending_payment' ? `/bookings/${b.id}/checkout` : `/bookings/${b.id}`}
                                        className="bg-card group flex items-center gap-4 rounded-2xl border p-4 shadow-sm transition hover:border-[#C8E64A] hover:shadow-md focus-visible:ring-2 focus-visible:ring-[#1D5C7A] focus-visible:outline-none"
                                    >
                                        <span
                                            className={`flex size-16 shrink-0 flex-col items-center justify-center rounded-xl ${b.is_arena ? 'bg-[#0E1B2C] text-[#C8E64A]' : 'bg-[#E3F0F7] text-[#1D5C7A]'}`}
                                        >
                                            <span className="text-2xl leading-none font-extrabold">{day}</span>
                                            <span className="text-[11px] font-semibold uppercase">{shortMonth(month)}</span>
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-bold">{b.court}</span>
                                                {b.is_arena && (
                                                    <span className="rounded-full bg-[#C8E64A] px-2 py-0.5 text-[10px] font-bold text-[#0E1B2C] uppercase">
                                                        Arena
                                                    </span>
                                                )}
                                            </span>
                                            <span className="block text-sm text-[#5F6B78]">
                                                {ucfirst(b.date)} · {b.time}
                                            </span>
                                            <span className="mt-1 flex items-center gap-3">
                                                <StatusBadge status={b.status} />
                                                <span className="text-sm font-semibold">{euros(b.price_cents)}</span>
                                            </span>
                                        </span>
                                        <ChevronRight className="size-5 text-[#5F6B78] transition group-hover:translate-x-0.5" aria-hidden />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
