import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

const field = 'bg-card mt-1 block w-full rounded-md border px-2 py-1.5';

// Client sans compte au comptoir : il paie sur place, le ticket imprimé porte son QR code d'accès.
export default function Counter({ courts, slots, today }: { courts: { id: number; name: string }[]; slots: string[]; today: string }) {
    const form = useForm({
        court_id: courts[0]?.id ?? 0,
        date: today,
        time: slots[0] ?? '',
        name: '',
        contact: '',
        method: 'cash',
    });
    const errors = form.errors as Record<string, string>;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tableau de bord', href: '/admin' },
                { title: 'Comptoir', href: '/admin/counter' },
            ]}
        >
            <Head title="Réservation au comptoir" />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow="Espace gérant" title="Réservation au comptoir" />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/counter');
                    }}
                    className="bg-card grid max-w-3xl gap-4 rounded-2xl border p-5 sm:grid-cols-2"
                >
                    <label className="text-sm">
                        Terrain
                        <select value={form.data.court_id} onChange={(e) => form.setData('court_id', Number(e.target.value))} className={field}>
                            {courts.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                        <label className="text-sm">
                            Date
                            <input
                                type="date"
                                min={today}
                                value={form.data.date}
                                onChange={(e) => form.setData('date', e.target.value)}
                                className={field}
                            />
                        </label>
                        <label className="text-sm">
                            Heure
                            <select value={form.data.time} onChange={(e) => form.setData('time', e.target.value)} className={field}>
                                {slots.map((s) => (
                                    <option key={s}>{s}</option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <label className="text-sm">
                        Nom du client
                        <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoComplete="off" className={field} />
                        <InputError message={errors.name} />
                    </label>
                    <label className="text-sm">
                        E-mail ou téléphone
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
                        <legend>Paiement sur place</legend>
                        {[
                            ['cash', 'Espèces'],
                            ['terminal', 'Terminal de paiement'],
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
                        Encaisser et confirmer
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
