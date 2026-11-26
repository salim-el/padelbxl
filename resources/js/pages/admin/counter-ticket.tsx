import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { euros } from '@/lib/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';

type Ticket = {
    id: string;
    court: string;
    date: string;
    time: string;
    name: string;
    contact: string;
    status: string;
    price_cents: number;
    invoice: string | null;
    credit_notes: string[];
    cancellable: boolean;
    refundable: boolean;
};

export default function CounterTicket({ booking, qr, qr_window }: { booking: Ticket; qr: string | null; qr_window: string | null }) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const cancel = () => {
        const question = booking.refundable
            ? t('Annuler la réservation de :name ? Le montant lui sera rendu.', { name: booking.name })
            : t('Annuler la réservation de :name ? Moins de 24 h avant, elle n’est pas remboursée.', { name: booking.name });
        if (confirm(question)) router.post(`/admin/counter/${booking.id}/cancel`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('Comptoir'), href: '/admin/counter' },
                { title: t('Ticket'), href: `/admin/counter/${booking.id}` },
            ]}
        >
            <Head title={t('Ticket – :name', { name: booking.name })} />
            <div className="flex flex-col gap-6 p-4">
                <div className="print:hidden">
                    <PageHeader eyebrow={t('Comptoir')} title={t('Ticket du client')}>
                        <Button onClick={() => window.print()} variant="outline" className="bg-transparent text-[#F7F6F1]">
                            <Printer className="size-4" aria-hidden /> {t('Imprimer')}
                        </Button>
                    </PageHeader>
                </div>

                {/* Seule cette carte s'imprime : le client repart avec son QR code. */}
                <section
                    aria-label={t('Ticket')}
                    className="bg-card mx-auto w-full max-w-sm rounded-2xl border p-5 text-center print:border-0 print:shadow-none"
                >
                    <p className="font-mono text-xs tracking-widest uppercase">ArenaBxl</p>
                    <h2 className="mt-1 text-xl font-extrabold">{booking.name}</h2>
                    <p className="mt-2">
                        {booking.court} · {booking.date} · {booking.time}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t(':price TTC payé', { price: euros(booking.price_cents) })}
                        {booking.invoice && ` · ${t('facture :number', { number: booking.invoice })}`}
                    </p>
                    {qr ? (
                        <>
                            <img src={qr} alt={t('QR code d’accès de :name', { name: booking.name })} className="mx-auto mt-4 size-56" />
                            <p className="text-sm">{t('Valable de :window, à présenter à la borne.', { window: qr_window ?? '' })}</p>
                        </>
                    ) : (
                        <div className="mt-4">
                            <StatusBadge status={booking.status} />
                        </div>
                    )}
                </section>

                <div className="mx-auto flex flex-wrap items-center gap-3 print:hidden">
                    <span className="text-muted-foreground text-sm">{t('Contact : :contact', { contact: booking.contact })}</span>
                    {booking.invoice && (
                        <a href={`/bookings/${booking.id}/invoices/${booking.invoice}/pdf`} download className="text-sm underline">
                            {t('Facture PDF')}
                        </a>
                    )}
                    {booking.invoice &&
                        booking.credit_notes.map((number) => (
                            <a
                                key={number}
                                href={`/bookings/${booking.id}/invoices/${booking.invoice}/credit-notes/${number}/pdf`}
                                download
                                className="text-sm underline"
                            >
                                {t('Note de crédit :number (PDF)', { number })}
                            </a>
                        ))}
                    {booking.cancellable && (
                        <Button variant="outline" className="border-[#CC3342]/40 text-[#9E2833] hover:bg-[#FCE6E8]" onClick={cancel}>
                            {t('Annuler la réservation')}
                        </Button>
                    )}
                    <Link href="/admin/counter" className="text-sm underline">
                        {t('Nouvelle réservation')}
                    </Link>
                </div>
                <InputError message={errors.booking} className="mx-auto" />
            </div>
        </AppLayout>
    );
}
