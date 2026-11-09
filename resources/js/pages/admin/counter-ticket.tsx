import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
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
    cancellable: boolean;
    refundable: boolean;
};

export default function CounterTicket({ booking, qr, qr_window }: { booking: Ticket; qr: string | null; qr_window: string | null }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const cancel = () => {
        const question = booking.refundable
            ? `Annuler la réservation de ${booking.name} ? Le montant lui sera rendu.`
            : `Annuler la réservation de ${booking.name} ? Moins de 24 h avant, elle n’est pas remboursée.`;
        if (confirm(question)) router.post(`/admin/counter/${booking.id}/cancel`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Comptoir', href: '/admin/counter' },
                { title: 'Ticket', href: `/admin/counter/${booking.id}` },
            ]}
        >
            <Head title={`Ticket – ${booking.name}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="print:hidden">
                    <PageHeader eyebrow="Comptoir" title="Ticket du client">
                        <Button onClick={() => window.print()} variant="outline" className="bg-transparent text-[#F7F6F1]">
                            <Printer className="size-4" aria-hidden /> Imprimer
                        </Button>
                    </PageHeader>
                </div>

                {/* Seule cette carte s'imprime : le client repart avec son QR code. */}
                <section
                    aria-label="Ticket"
                    className="bg-card mx-auto w-full max-w-sm rounded-2xl border p-5 text-center print:border-0 print:shadow-none"
                >
                    <p className="font-mono text-xs tracking-widest uppercase">PadelBxl</p>
                    <h2 className="mt-1 text-xl font-extrabold">{booking.name}</h2>
                    <p className="mt-2">
                        {booking.court} · {booking.date} · {booking.time}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {euros(booking.price_cents)} TTC payé
                        {booking.invoice && ` · facture ${booking.invoice}`}
                    </p>
                    {qr ? (
                        <>
                            <img src={qr} alt={`QR code d’accès de ${booking.name}`} className="mx-auto mt-4 size-56" />
                            <p className="text-sm">Valable de {qr_window}, à présenter à la borne.</p>
                        </>
                    ) : (
                        <div className="mt-4">
                            <StatusBadge status={booking.status} />
                        </div>
                    )}
                </section>

                <div className="mx-auto flex flex-wrap items-center gap-3 print:hidden">
                    <span className="text-muted-foreground text-sm">Contact : {booking.contact}</span>
                    {booking.cancellable && (
                        <Button variant="outline" className="border-[#CC3342]/40 text-[#9E2833] hover:bg-[#FCE6E8]" onClick={cancel}>
                            Annuler la réservation
                        </Button>
                    )}
                    <Link href="/admin/counter" className="text-sm underline">
                        Nouvelle réservation
                    </Link>
                </div>
                <InputError message={errors.booking} className="mx-auto" />
            </div>
        </AppLayout>
    );
}
