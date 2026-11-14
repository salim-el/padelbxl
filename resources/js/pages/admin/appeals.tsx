import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';

type Appeal = {
    id: number;
    player: string;
    reason: string;
    suspended_by: string | null;
    can_decide: boolean;
    message: string;
    status: 'received' | 'accepted' | 'rejected';
    decision_note: string | null;
    created_at: string;
};
const LABELS = { received: 'À examiner', accepted: 'Acceptée', rejected: 'Refusée' };

function Decision({ appeal }: { appeal: Appeal }) {
    const { data, setData, post, processing } = useForm<{ accepted: boolean; decision_note: string }>({ accepted: true, decision_note: '' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                post(`/admin/appeals/${appeal.id}/decision`, { preserveScroll: true });
            }}
            className="mt-3 flex flex-col gap-2"
        >
            <div className="flex gap-4">
                <label className="flex items-center gap-2">
                    <input type="radio" checked={data.accepted} onChange={() => setData('accepted', true)} /> Accepter et réactiver
                </label>
                <label className="flex items-center gap-2">
                    <input type="radio" checked={!data.accepted} onChange={() => setData('accepted', false)} /> Refuser
                </label>
            </div>
            <label className="flex flex-col gap-1">
                Motivation (envoyée au joueur)
                <textarea
                    required
                    minLength={10}
                    rows={3}
                    value={data.decision_note}
                    onChange={(e) => setData('decision_note', e.target.value)}
                    className="rounded-lg border border-[#DAD7CC] bg-white p-2"
                />
            </label>
            <Button type="submit" size="sm" disabled={processing} className="self-start">
                Enregistrer la décision
            </Button>
        </form>
    );
}

// F29 : contestations des suspensions. Celui qui a suspendu ne décide pas.
export default function Appeals({ appeals }: { appeals: Appeal[] }) {
    const { errors } = usePage().props as { errors: Record<string, string> };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tableau de bord', href: '/admin' },
                { title: 'Contestations', href: '/admin/appeals' },
            ]}
        >
            <Head title="Contestations de suspension" />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow="Espace gérant" title="Contestations de suspension" />
                {errors.appeal && (
                    <p role="alert" className="text-sm text-[#9E2833]">
                        {errors.appeal}
                    </p>
                )}
                {appeals.length === 0 && <p className="text-sm text-[#5F6B78]">Aucune contestation.</p>}
                <ul className="grid gap-3">
                    {appeals.map((a) => (
                        <li key={a.id} className="bg-card rounded-2xl border p-4 text-sm">
                            <p className="font-semibold">
                                {a.player} · {a.created_at} · {LABELS[a.status]}
                            </p>
                            <p className="mt-2">
                                <span className="text-[#5F6B78]">Motif de la suspension{a.suspended_by ? ` (par ${a.suspended_by})` : ''} : </span>
                                {a.reason}
                            </p>
                            <p className="mt-1 whitespace-pre-line">
                                <span className="text-[#5F6B78]">Réponse du joueur : </span>
                                {a.message}
                            </p>
                            {a.decision_note && <p className="mt-1 text-[#5F6B78]">Décision : {a.decision_note}</p>}
                            {a.can_decide && <Decision appeal={a} />}
                            {a.status === 'received' && !a.can_decide && (
                                <p className="mt-2 text-[#5F6B78]">Vous avez suspendu ce compte : une autre personne doit décider.</p>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
