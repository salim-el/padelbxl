import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Member = { id: number; name: string; email: string; role: 'manager' | 'admin'; two_factor: boolean; is_me: boolean };
const ROLES = { manager: 'Gérant', admin: 'Administrateur' };
const field = 'bg-card mt-1 block w-full rounded-md border px-2 py-1.5';

function Invite() {
    const form = useForm({ name: '', email: '', role: 'manager' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/staff', { preserveScroll: true, onSuccess: () => form.reset() });
            }}
            className="grid gap-2 text-sm sm:grid-cols-4"
        >
            <label>
                Nom
                <input required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={field} />
            </label>
            <label>
                E-mail
                <input
                    type="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value.toLowerCase())}
                    className={field}
                />
            </label>
            <label>
                Rôle
                <select value={form.data.role} onChange={(e) => form.setData('role', e.target.value)} className={field}>
                    <option value="manager">Gérant</option>
                    <option value="admin">Administrateur</option>
                </select>
            </label>
            <div className="flex items-end">
                <Button type="submit" disabled={form.processing} className="w-full">
                    Ajouter
                </Button>
            </div>
        </form>
    );
}

// F35 : l'administrateur gère les comptes du personnel. Un joueur existant peut être promu avec son e-mail.
export default function Staff({ staff }: { staff: Member[] }) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const setRole = (m: Member, role: string) => {
        if (role === 'player' && !confirm(`${m.name} quittera le personnel et gardera un compte joueur. Continuer ?`)) return;
        router.patch(`/admin/staff/${m.id}`, { role }, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tableau de bord', href: '/admin' },
                { title: 'Personnel', href: '/admin/staff' },
            ]}
        >
            <Head title="Personnel" />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow="Administration" title="Personnel du club" />
                <section>
                    <h2 className="text-lg font-semibold">Ajouter un membre</h2>
                    <p className="mb-2 text-sm text-[#5F6B78]">
                        Un nouveau compte reçoit un lien pour choisir son mot de passe. La double authentification est demandée à la première connexion.
                    </p>
                    <Invite />
                </section>
                {(errors.staff || errors.email || errors.name) && (
                    <p role="alert" className="text-sm text-[#9E2833]">
                        {errors.staff ?? errors.email ?? errors.name}
                    </p>
                )}
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2">Nom</th>
                            <th>E-mail</th>
                            <th>Double authentification</th>
                            <th>Rôle</th>
                            <th>
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {staff.map((m) => (
                            <tr key={m.id} className="border-b">
                                <td className="py-2">{m.name}</td>
                                <td>{m.email}</td>
                                <td>{m.two_factor ? 'Activée' : 'Pas encore'}</td>
                                <td>
                                    {m.is_me ? (
                                        ROLES[m.role]
                                    ) : (
                                        <select
                                            aria-label={`Rôle de ${m.name}`}
                                            value={m.role}
                                            onChange={(e) => setRole(m, e.target.value)}
                                            className="rounded-md border px-2 py-1"
                                        >
                                            <option value="manager">Gérant</option>
                                            <option value="admin">Administrateur</option>
                                            <option value="player">Retirer du personnel</option>
                                        </select>
                                    )}
                                </td>
                                <td>
                                    {!m.is_me && !m.two_factor && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => router.post(`/admin/staff/${m.id}/resend`, {}, { preserveScroll: true })}
                                        >
                                            Renvoyer le lien
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
