import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { DISPUTE_LABELS } from '@/pages/disputes/index';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Dispute = {
    id: number;
    player: string;
    court: string;
    starts_at: string;
    status: string;
    reason: string;
    has_attachment: boolean;
    decision_note: string | null;
    decided_by: string | null;
    refunded_cents: number | null;
    refundable_cents: number;
    created_at: string;
};

const euros = (cents: number) => (cents / 100).toLocaleString('fr-BE', { style: 'currency', currency: 'EUR' });
const DECISIONS: [string, string][] = [
    ['accepted_full', 'Accepter et rembourser tout'],
    ['accepted_partial', 'Accepter et rembourser une partie'],
    ['rejected', 'Refuser'],
];

// F27 : le gérant lit la demande, la pièce éventuelle, puis décide avec une motivation.
export default function AdminDispute({ dispute }: { dispute: Dispute }) {
    const t = useT();
    const open = ['received', 'in_review'].includes(dispute.status);
    const { data, setData, post, processing, errors } = useForm({ decision: 'accepted_full', decision_note: '', amount: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/admin/disputes/${dispute.id}/decision`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('Demandes'), href: '/admin/disputes' },
                { title: t('Demande :id', { id: dispute.id }), href: `/admin/disputes/${dispute.id}` },
            ]}
        >
            <Head title={t('Demande :id', { id: dispute.id })} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <PageHeader eyebrow={`${dispute.court} · ${dispute.starts_at}`} title={t('Demande de :name', { name: dispute.player })} />
                <section className="bg-card rounded-2xl border p-5 text-sm">
                    <p className="text-[#5F6B78]">
                        {t('Reçue le :date', { date: dispute.created_at })} · {t(DISPUTE_LABELS[dispute.status] ?? dispute.status)}
                    </p>
                    <p className="mt-3 whitespace-pre-line">{dispute.reason}</p>
                    {dispute.has_attachment && (
                        <a href={`/admin/disputes/${dispute.id}/attachment`} className="mt-3 inline-block font-semibold text-[#1D5C7A] underline">
                            {t('Télécharger la pièce justificative')}
                        </a>
                    )}
                </section>

                {open ? (
                    <form onSubmit={submit} className="bg-card flex flex-col gap-4 rounded-2xl border p-5 text-sm">
                        <p>{t('Encore remboursable sur ses paiements : :amount.', { amount: euros(dispute.refundable_cents) })}</p>
                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1 font-medium">{t('Décision')}</legend>
                            {DECISIONS.map(([value, label]) => (
                                <label key={value} className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="decision"
                                        value={value}
                                        checked={data.decision === value}
                                        onChange={() => setData('decision', value)}
                                    />
                                    {t(label)}
                                </label>
                            ))}
                        </fieldset>
                        {data.decision === 'accepted_partial' && (
                            <label className="flex flex-col gap-1 font-medium">
                                {t('Montant à rembourser (€)')}
                                <input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    required
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                    className="h-9 w-40 rounded-md border border-[#DAD7CC] bg-white px-3 font-normal"
                                />
                            </label>
                        )}
                        <label className="flex flex-col gap-1 font-medium">
                            {t('Motivation (envoyée au joueur)')}
                            <textarea
                                required
                                minLength={10}
                                rows={4}
                                value={data.decision_note}
                                onChange={(e) => setData('decision_note', e.target.value)}
                                className="rounded-lg border border-[#DAD7CC] bg-white p-3 font-normal"
                            />
                        </label>
                        {(errors.decision || errors.decision_note || errors.amount) && (
                            <p role="alert" className="text-[#9E2833]">
                                {errors.decision ?? errors.decision_note ?? errors.amount}
                            </p>
                        )}
                        <Button type="submit" disabled={processing}>
                            {t('Enregistrer la décision')}
                        </Button>
                    </form>
                ) : (
                    <section className="bg-card rounded-2xl border p-5 text-sm">
                        <p className="font-semibold">
                            {t('Décision de :name', { name: dispute.decided_by ?? '' })}
                            {dispute.refunded_cents ? ` · ${t(':amount remboursés', { amount: euros(dispute.refunded_cents) })}` : ''}
                        </p>
                        <p className="mt-2 whitespace-pre-line">{dispute.decision_note}</p>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
