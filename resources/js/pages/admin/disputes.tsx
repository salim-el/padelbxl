import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { DISPUTE_LABELS } from '@/pages/disputes/index';
import { Head, Link } from '@inertiajs/react';

type Dispute = { id: number; player: string; court: string; starts_at: string; status: string; created_at: string };

// F27 : demandes exceptionnelles, les ouvertes d'abord.
export default function AdminDisputes({ disputes }: { disputes: Dispute[] }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tableau de bord', href: '/admin' },
                { title: 'Demandes', href: '/admin/disputes' },
            ]}
        >
            <Head title="Demandes exceptionnelles" />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow="Espace gérant" title="Demandes exceptionnelles" />
                <div className="bg-card overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <caption className="sr-only">Demandes exceptionnelles, les ouvertes d’abord</caption>
                        <thead>
                            <tr className="border-b text-left">
                                <th className="p-2">Reçue le</th>
                                <th className="p-2">Joueur</th>
                                <th className="p-2">Réservation</th>
                                <th className="p-2">État</th>
                                <th className="p-2">
                                    <span className="sr-only">Ouvrir</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {disputes.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-3 text-[#5F6B78]">
                                        Aucune demande.
                                    </td>
                                </tr>
                            )}
                            {disputes.map((d) => (
                                <tr key={d.id} className="border-b last:border-0">
                                    <td className="p-2">{d.created_at}</td>
                                    <td className="p-2">{d.player}</td>
                                    <td className="p-2">
                                        {d.court} · {d.starts_at}
                                    </td>
                                    <td className="p-2">{DISPUTE_LABELS[d.status] ?? d.status}</td>
                                    <td className="p-2">
                                        <Link href={`/admin/disputes/${d.id}`} className="font-semibold text-[#1D5C7A] underline">
                                            Examiner
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
