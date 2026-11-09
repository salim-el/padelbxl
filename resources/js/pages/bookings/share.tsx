import { BackButton } from '@/components/back-button';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { euros, SHARE_LABELS, ucfirst } from '@/lib/money';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, FileText, Users } from 'lucide-react';

type Props = {
    booking: { id: string; court: string; is_arena: boolean; date: string; time: string; status: string };
    organizer: string;
    share_cents: number;
    share_status: string;
    payable: boolean;
    declinable: boolean;
    qr: string | null;
    qr_window: string | null;
    invoices: { number: string; total_cents: number; vat_cents: number }[];
};

export default function Share({ booking, organizer, share_cents, share_status, payable, declinable, qr, qr_window, invoices }: Props) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm<{ accept_terms: boolean }>({ accept_terms: false });
    const pageErrors = usePage<{ errors: Record<string, string> }>().props.errors;

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('Mes réservations'), href: '/bookings' },
                { title: booking.court, href: '#' },
            ]}
        >
            <Head title={t('Ma part')} />
            <div className="mx-auto flex w-full max-w-lg flex-col gap-5 p-4">
                <BackButton href="/bookings">{t('Mes réservations')}</BackButton>
                <PageHeader eyebrow={t('Invitation de :name', { name: organizer })} title={booking.court}>
                    <StatusBadge status={booking.status} />
                </PageHeader>

                <section className="bg-card rounded-2xl border p-6 shadow-sm">
                    <ul className="space-y-2 text-sm">
                        <li className="flex items-center gap-2">
                            <CalendarDays className="size-4 text-[#1D5C7A]" aria-hidden /> {ucfirst(booking.date)}
                        </li>
                        <li className="flex items-center gap-2">
                            <Clock className="size-4 text-[#1D5C7A]" aria-hidden /> {booking.time}
                        </li>
                        <li className="flex items-center gap-2">
                            <Users className="size-4 text-[#1D5C7A]" aria-hidden /> {t(SHARE_LABELS[share_status] ?? share_status)}
                        </li>
                    </ul>
                    <div className="mt-5 flex items-end justify-between border-t border-dashed pt-4">
                        <span className="text-sm text-[#5F6B78]">{t('Votre part, TVA comprise')}</span>
                        <span className="text-3xl font-extrabold">{euros(share_cents)}</span>
                    </div>
                </section>

                {pageErrors.booking && (
                    <p role="alert" className="rounded-lg border border-[#9E2833]/30 bg-[#FCE6E8] p-3 text-sm text-[#9E2833]">
                        {pageErrors.booking}
                    </p>
                )}

                {payable && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(`/bookings/${booking.id}/share`);
                        }}
                        className="flex flex-col gap-3"
                    >
                        <p className="rounded-lg bg-[#E6ECF2] p-3 text-sm text-[#17304A]">
                            {t('Version alpha : le paiement est simulé. Aucune carte n’est débitée.')}
                        </p>
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5 size-4 accent-[#1D5C7A]"
                                checked={data.accept_terms}
                                onChange={(e) => setData('accept_terms', e.target.checked)}
                            />
                            {t('J’accepte les conditions générales et la règle d’annulation de 24 heures.')}
                        </label>
                        {errors.accept_terms && <p className="text-sm text-[#9E2833]">{t('Vous devez accepter les conditions pour réserver.')}</p>}
                        <Button type="submit" size="lg" className="h-12 text-base font-bold" disabled={processing}>
                            {t('Payer :amount', { amount: euros(share_cents) })}
                        </Button>
                        <p className="text-sm text-[#5F6B78]">{t('Sans paiement 24 h avant le match, l’organisateur règle votre part.')}</p>
                    </form>
                )}

                {declinable && (
                    <Button
                        variant="outline"
                        disabled={processing}
                        onClick={() => {
                            if (window.confirm(t('Refuser l’invitation ? Votre place sera proposée aux autres membres du club.'))) {
                                router.post(`/bookings/${booking.id}/share/decline`);
                            }
                        }}
                    >
                        {t('Je ne peux pas venir')}
                    </Button>
                )}

                {qr && (
                    <div className="flex flex-col items-center rounded-2xl border bg-white px-6 py-6 text-center text-[#0E1B2C] shadow-lg">
                        <img src={qr} alt={t('QR code d’accès au terrain')} className="size-56 rounded-lg border p-2" />
                        <p className="mt-3 text-sm font-semibold">{t('Votre QR code personnel')}</p>
                        <p className="text-sm text-[#5F6B78]">{t('Valable de :window, pour ce terrain uniquement.', { window: qr_window ?? '' })}</p>
                    </div>
                )}

                {invoices.map((i) => (
                    <p key={i.number} className="bg-card flex items-center justify-between gap-3 rounded-xl border p-3 text-sm">
                        <span className="flex items-center gap-2">
                            <FileText className="size-4 text-[#1D5C7A]" aria-hidden /> {t('Facture :number', { number: i.number })}
                        </span>
                        <span>
                            <strong>{euros(i.total_cents)}</strong>{' '}
                            <span className="text-[#5F6B78]">{t('dont TVA :vat', { vat: euros(i.vat_cents) })}</span>
                        </span>
                    </p>
                ))}
            </div>
        </AppLayout>
    );
}
