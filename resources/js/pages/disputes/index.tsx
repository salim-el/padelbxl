import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

type Dispute = {
    id: number;
    court: string;
    starts_at: string;
    status: string;
    decision_note: string | null;
    refunded_cents: number | null;
    created_at: string;
};

export const DISPUTE_LABELS: Record<string, string> = {
    received: 'Reçue',
    in_review: 'En examen',
    accepted_full: 'Acceptée',
    accepted_partial: 'Acceptée en partie',
    accepted_credit: 'Acceptée en avoir',
    rejected: 'Refusée',
};

const euros = (cents: number) => (cents / 100).toLocaleString('fr-BE', { style: 'currency', currency: 'EUR' });

// F17 : le joueur suit l'état de ses demandes : reçue, en examen, acceptée ou refusée.
export default function Disputes({ disputes }: { disputes: Dispute[] }) {
    const t = useT();

    return (
        <AppLayout breadcrumbs={[{ title: t('Mes demandes'), href: '/disputes' }]}>
            <Head title={t('Mes demandes')} />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow={t('Mon compte')} title={t('Mes demandes exceptionnelles')} />
                {disputes.length === 0 ? (
                    <p className="text-sm text-[#5F6B78]">{t('Aucune demande pour le moment. Elle s’introduit depuis une réservation payée.')}</p>
                ) : (
                    <ul className="grid gap-3">
                        {disputes.map((d) => (
                            <li key={d.id} className="bg-card rounded-2xl border p-4 text-sm">
                                <p className="font-semibold">
                                    {d.court} · {d.starts_at}
                                </p>
                                <p className="mt-1">
                                    {t('Introduite le :date', { date: d.created_at })} · <strong>{t(DISPUTE_LABELS[d.status] ?? d.status)}</strong>
                                    {d.refunded_cents ? ` · ${t('remboursé :amount', { amount: euros(d.refunded_cents) })}` : ''}
                                </p>
                                {d.decision_note && <p className="mt-2 text-[#5F6B78]">{d.decision_note}</p>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
