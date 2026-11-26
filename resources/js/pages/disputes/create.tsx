import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// F17 : demande pour un imprévu grave (maladie, accident, force majeure), même après le délai de 24 heures.
export default function CreateDispute({ booking }: { booking: { id: string; court: string; starts_at: string } }) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm<{ reason: string; attachment: File | null }>({ reason: '', attachment: null });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/bookings/${booking.id}/dispute`, { forceFormData: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('Mes réservations'), href: '/bookings' }]}>
            <Head title={t('Demande exceptionnelle')} />
            <div className="flex max-w-2xl flex-col gap-6 p-4">
                <PageHeader eyebrow={`${booking.court} · ${booking.starts_at}`} title={t('Demande exceptionnelle')} />
                <p className="text-sm text-[#5F6B78]">
                    {t(
                        'Maladie, accident ou cas de force majeure : expliquez ce qui s’est passé. Le gérant examine chaque demande et vous répond par e-mail. Une seule demande par réservation, au plus tard sept jours après le match.',
                    )}
                </p>
                <form onSubmit={submit} className="bg-card flex flex-col gap-4 rounded-2xl border p-5">
                    <label className="flex flex-col gap-2 text-sm font-medium">
                        {t('Motif')}
                        <textarea
                            required
                            minLength={20}
                            maxLength={2000}
                            rows={6}
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            className="rounded-lg border border-[#DAD7CC] bg-white p-3 font-normal"
                        />
                    </label>
                    {errors.reason && (
                        <p role="alert" className="text-sm text-[#9E2833]">
                            {errors.reason}
                        </p>
                    )}
                    <label className="flex flex-col gap-2 text-sm font-medium">
                        {t('Pièce justificative (facultative) : PDF ou image, 5 Mo au plus')}
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setData('attachment', e.target.files?.[0] ?? null)} />
                    </label>
                    {errors.attachment && (
                        <p role="alert" className="text-sm text-[#9E2833]">
                            {errors.attachment}
                        </p>
                    )}
                    <p className="text-xs text-[#5F6B78]">{t('La pièce reste privée : seul le personnel du club peut l’ouvrir.')}</p>
                    <Button type="submit" disabled={processing}>
                        {t('Envoyer la demande')}
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
