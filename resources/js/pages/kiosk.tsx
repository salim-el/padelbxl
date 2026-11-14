import { Button } from '@/components/ui/button';
import { Head, useForm } from '@inertiajs/react';

type Result = { result: 'granted' | 'refused'; reason: string | null; first_name: string | null } | null;
const REASONS: Record<string, string> = {
    unknown: 'QR code inconnu',
    revoked: 'Réservation annulée',
    wrong_court: 'Mauvais terrain',
    too_early: 'Trop tôt : accès 15 minutes avant le créneau',
    expired: 'Créneau terminé',
    already_used: 'QR code déjà utilisé',
};

export default function Kiosk({ courts, result }: { courts: { id: number; name: string }[]; result: Result }) {
    const { data, setData, post, processing } = useForm({ court_id: courts[0]?.id ?? 0, token: '' });

    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-[#0E1B2C] p-6 text-[#F7F6F1]">
            <Head title="Borne d’accès">
                <meta name="robots" content="noindex" />
            </Head>
            <div className="text-center">
                <p className="font-mono text-sm tracking-widest text-[#C8E64A] uppercase">Arena Padel Club · Entrée</p>
                <h1 className="mt-2 text-3xl font-extrabold uppercase">Borne d’accès</h1>
            </div>
            {result && (
                <div
                    role="status"
                    className={`rounded-xl p-6 text-center text-2xl font-bold ${result.result === 'granted' ? 'bg-[#C8E64A] text-[#0E1B2C]' : 'bg-[#CC3342] text-[#F7F6F1]'}`}
                >
                    {result.result === 'granted'
                        ? `Accès autorisé. Bon match, ${result.first_name ?? ''}`
                        : `Accès refusé : ${REASONS[result.reason ?? ''] ?? result.reason}`}
                </div>
            )}
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/kiosk', { onSuccess: () => setData('token', '') });
                }}
                className="flex w-full max-w-md flex-col gap-3"
            >
                <label htmlFor="court" className="text-sm font-semibold text-[#F7F6F1]/90">
                    Terrain de cette borne
                </label>
                <select
                    id="court"
                    className="rounded-md border-2 border-[#C8E64A]/60 bg-white p-2 text-[#0E1B2C] focus:border-[#C8E64A] focus:outline-none"
                    value={data.court_id}
                    onChange={(e) => setData('court_id', Number(e.target.value))}
                >
                    {courts.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </select>
                <label htmlFor="token" className="text-sm font-semibold text-[#F7F6F1]/90">
                    Contenu du QR code (simulation du lecteur)
                </label>
                <input
                    id="token"
                    className="rounded-md border-2 border-[#C8E64A]/60 bg-white p-2 text-[#0E1B2C] focus:border-[#C8E64A] focus:outline-none"
                    value={data.token}
                    onChange={(e) => setData('token', e.target.value)}
                    autoComplete="off"
                />
                <Button
                    type="submit"
                    disabled={processing || !data.token}
                    className="bg-[#C8E64A] font-bold text-[#0E1B2C] hover:bg-[#b5d23c] disabled:bg-[#C8E64A]/80 disabled:text-[#0E1B2C] disabled:opacity-100"
                >
                    Valider
                </Button>
            </form>
        </div>
    );
}
