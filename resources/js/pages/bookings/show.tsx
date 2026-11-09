import { BackButton } from '@/components/back-button';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { euros, SHARE_LABELS, ucfirst } from '@/lib/money';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, FileText, RotateCcw, Users } from 'lucide-react';

type Booking = {
    id: string;
    court: string;
    date: string;
    time: string;
    status: string;
    price_cents: number;
    free_cancellation_until: string;
    refundable: boolean;
    cancellable: boolean;
};
type Props = {
    booking: Booking;
    qr: string | null;
    qr_window: string | null;
    items: { name: string; quantity: number; total_cents: number }[];
    invoices: { number: string; total_cents: number; vat_cents: number; ubl: boolean }[];
    players: { name: string | null; is_organizer: boolean; open: boolean; taken: boolean; share_cents: number; status: string }[];
};

export default function Show({ booking, qr, qr_window, items, invoices, players }: Props) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const cancel = () => {
        if (confirm(t('Annuler cette réservation ?'))) router.post(`/bookings/${booking.id}/cancel`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('Mes réservations'), href: '/bookings' },
                { title: booking.court, href: '#' },
            ]}
        >
            <Head title={t('Réservation')} />
            <div className="mx-auto flex w-full max-w-lg flex-col gap-5 p-4">
                {errors.booking && (
                    <p role="alert" className="rounded-lg border border-[#9E2833]/30 bg-[#FCE6E8] p-3 text-sm text-[#9E2833]">
                        {errors.booking}
                    </p>
                )}
                <BackButton href="/bookings">{t('Mes réservations')}</BackButton>

                {/* Billet : en-tête marine, QR code au centre, facture en pied. */}
                <article className="overflow-hidden rounded-2xl border bg-white text-[#0E1B2C] shadow-lg">
                    <header className="relative overflow-hidden bg-[#0E1B2C] px-6 py-5 text-[#F7F6F1]">
                        <img src="/images/hero-800.webp" alt="" aria-hidden className="absolute inset-0 h-full w-full object-cover opacity-20" />
                        <div className="relative">
                            <div className="flex items-center justify-between gap-3">
                                <p className="font-mono text-xs tracking-widest text-[#C8E64A] uppercase">{t('Partie classique')}</p>
                                <StatusBadge status={booking.status} />
                            </div>
                            <h1 className="mt-1 text-3xl font-extrabold uppercase">{booking.court}</h1>
                            <p className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                <span className="flex items-center gap-1.5">
                                    <CalendarDays className="size-4" aria-hidden /> {ucfirst(booking.date)}
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <Clock className="size-4" aria-hidden /> {booking.time}
                                </span>
                            </p>
                        </div>
                    </header>

                    {qr ? (
                        <div className="flex flex-col items-center px-6 py-6 text-center">
                            <img src={qr} alt={t('QR code d’accès au terrain')} className="size-56 rounded-lg border p-2" />
                            <p className="mt-3 text-sm font-semibold">{t('Votre QR code personnel')}</p>
                            <p className="text-sm text-[#5F6B78]">
                                {t('Valable de :window, pour ce terrain uniquement.', { window: qr_window ?? '' })}
                            </p>
                        </div>
                    ) : (
                        <p className="px-6 py-6 text-center text-sm text-[#5F6B78]">{t('Aucun QR code actif pour cette réservation.')}</p>
                    )}

                    {/* Découpe du billet. */}
                    <div className="relative border-t-2 border-dashed border-[#DAD7CC]" aria-hidden>
                        <span className="bg-background absolute -top-3 -left-3 size-6 rounded-full" />
                        <span className="bg-background absolute -top-3 -right-3 size-6 rounded-full" />
                    </div>

                    <footer className="space-y-1 px-6 py-4 text-sm">
                        {items.map((i) => (
                            <p key={i.name} className="flex justify-between gap-3 text-[#5F6B78]">
                                <span>
                                    {i.quantity} × {i.name}
                                </span>
                                <span>{euros(i.total_cents)}</span>
                            </p>
                        ))}
                        {invoices.length > 0 ? (
                            invoices.map((i) => (
                                <p key={i.number} className="flex items-center justify-between gap-3">
                                    <span className="flex items-center gap-2">
                                        <FileText className="size-4 text-[#1D5C7A]" aria-hidden /> {t('Facture :number', { number: i.number })}
                                        {i.ubl && (
                                            <a
                                                href={`/bookings/${booking.id}/invoices/${i.number}/ubl`}
                                                download
                                                className="text-[#1D5C7A] underline"
                                            >
                                                {t('Facture électronique (UBL)')}
                                            </a>
                                        )}
                                    </span>
                                    <span>
                                        <strong>{euros(i.total_cents)}</strong>{' '}
                                        <span className="text-[#5F6B78]">{t('dont TVA :vat', { vat: euros(i.vat_cents) })}</span>
                                    </span>
                                </p>
                            ))
                        ) : (
                            <p className="flex justify-between">
                                <span>{t('Prix')}</span>
                                <strong>{euros(booking.price_cents)}</strong>
                            </p>
                        )}
                    </footer>
                </article>

                {players.length > 0 && (
                    <section className="bg-card rounded-2xl border p-5" aria-labelledby="partage">
                        <h2 id="partage" className="mb-3 flex items-center gap-2 font-bold">
                            <Users className="size-4 text-[#1D5C7A]" aria-hidden /> {t('Prix partagé')}
                        </h2>
                        <ul className="space-y-2 text-sm">
                            {players.map((p, i) => (
                                <li key={i} className="flex items-center justify-between gap-3">
                                    <span className="min-w-0 truncate">
                                        {p.open ? t(p.taken ? 'Place ouverte, prise par un membre' : 'Place ouverte, encore libre') : p.name}{' '}
                                        {p.is_organizer && <span className="text-[#5F6B78]">({t('vous')})</span>}
                                    </span>
                                    <span className="flex shrink-0 items-center gap-2">
                                        <span className="text-[#5F6B78]">{t(SHARE_LABELS[p.status] ?? p.status)}</span>
                                        <strong>{euros(p.share_cents)}</strong>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {['confirmed', 'used'].includes(booking.status) && (
                    <button
                        type="button"
                        onClick={() => router.post(`/bookings/${booking.id}/rematch`)}
                        className="flex items-center justify-center gap-2 rounded-lg bg-[#C8E64A] px-4 py-3 font-bold text-[#0E1B2C] transition hover:bg-[#b5d23c] focus-visible:ring-2 focus-visible:ring-[#1D5C7A] focus-visible:outline-none"
                    >
                        <RotateCcw className="size-4" aria-hidden /> {t('Revanche : même terrain, même heure, la semaine prochaine')}
                    </button>
                )}
                {booking.cancellable && (
                    <div className="bg-card flex flex-col gap-3 rounded-2xl border p-5">
                        <p className="text-sm">
                            {booking.refundable
                                ? t('Remboursement complet si vous annulez avant le :date.', { date: booking.free_cancellation_until })
                                : t('Le match commence dans moins de 24 h : une annulation ne sera pas remboursée.')}
                        </p>
                        <button
                            type="button"
                            onClick={cancel}
                            className="rounded-lg border border-[#CC3342] px-4 py-2.5 text-sm font-semibold text-[#9E2833] transition hover:bg-[#FCE6E8] focus-visible:ring-2 focus-visible:ring-[#CC3342] focus-visible:outline-none"
                        >
                            {t('Annuler la réservation')}
                        </button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
