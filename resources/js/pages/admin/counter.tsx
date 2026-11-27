import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { SPORT_LABELS } from '@/lib/money';
import { Head, useForm } from '@inertiajs/react';

const field = 'bg-card mt-1 block w-full rounded-md border px-2 py-1.5';

type Court = { id: number; name: string; sport: string };

// Client sans compte au comptoir : il paie sur place, le ticket imprimé porte son QR code d'accès.
export default function Counter({ courts, slots, today }: { courts: Court[]; slots: Record<string, string[]>; today: string }) {
    const t = useT();
    // Les heures proposées sont celles du sport du terrain : toutes les 90 minutes au padel, toutes les heures au foot.
    const timesOf = (id: number) => slots[courts.find((c) => c.id === id)?.sport ?? 'padel'] ?? [];
    const form = useForm({
        court_id: courts[0]?.id ?? 0,
        date: today,
        time: timesOf(courts[0]?.id ?? 0)[0] ?? '',
        name: '',
        contact: '',
        method: 'cash',
    });
    const errors = form.errors as Record<string, string>;
    // Un autre terrain garde l'heure choisie si son sport la propose, sinon il prend sa première heure.
    const pickCourt = (id: number) => {
        const next = timesOf(id);
        form.setData((d) => ({ ...d, court_id: id, time: next.includes(d.time) ? d.time : (next[0] ?? '') }));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('Tableau de bord'), href: '/admin' },
                { title: t('Comptoir'), href: '/admin/counter' },
            ]}
        >
            <Head title={t('Réservation au comptoir')} />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow={t('Espace gérant')} title={t('Réservation au comptoir')} />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/counter');
                    }}
                    className="bg-card grid max-w-3xl gap-4 rounded-2xl border p-5 sm:grid-cols-2"
                >
                    <label className="text-sm">
                        {t('Terrain')}
                        <select value={form.data.court_id} onChange={(e) => pickCourt(Number(e.target.value))} className={field}>
                            {courts.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name} · {t(SPORT_LABELS[c.sport])}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                        <label className="text-sm">
                            {t('Date')}
                            <input
                                type="date"
                                min={today}
                                value={form.data.date}
                                onChange={(e) => form.setData('date', e.target.value)}
                                className={field}
                            />
                        </label>
                        <label className="text-sm">
                            {t('Heure')}
                            <select value={form.data.time} onChange={(e) => form.setData('time', e.target.value)} className={field}>
                                {timesOf(form.data.court_id).map((s) => (
                                    <option key={s}>{s}</option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <label className="text-sm">
                        {t('Nom du client')}
                        <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoComplete="off" className={field} />
                        <InputError message={errors.name} />
                    </label>
                    <label className="text-sm">
                        {t('E-mail ou téléphone')}
                        <input
                            value={form.data.contact}
                            onChange={(e) => form.setData('contact', e.target.value)}
                            autoComplete="off"
                            placeholder="0470 12 34 56"
                            className={field}
                        />
                        <InputError message={errors.contact} />
                    </label>
                    <fieldset className="text-sm">
                        <legend>{t('Paiement sur place')}</legend>
                        {[
                            ['cash', t('Espèces')],
                            ['terminal', t('Terminal de paiement')],
                        ].map(([value, label]) => (
                            <label key={value} className="mt-1 flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="method"
                                    value={value}
                                    checked={form.data.method === value}
                                    onChange={() => form.setData('method', value)}
                                />
                                {label}
                            </label>
                        ))}
                    </fieldset>

                    {(errors.booking || errors.date || errors.time) && (
                        <p role="alert" className="text-sm text-[#9E2833] sm:col-span-2">
                            {errors.booking ?? errors.date ?? errors.time}
                        </p>
                    )}
                    <Button type="submit" disabled={form.processing} className="sm:col-span-2">
                        {t('Encaisser et confirmer')}
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
