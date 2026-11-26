import { useT } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

type Props = { court: string; date: string; time: string; window: string | null; qr: string | null; used: boolean };

// F18 et F25 : QR code personnel d'un joueur sans compte. Pas de connexion : le lien signé suffit.
export default function Pass({ court, date, time, window, qr, used }: Props) {
    const t = useT();

    return (
        <main className="flex min-h-screen flex-col items-center justify-center gap-4 bg-[#F7F6F1] p-6 text-center text-[#0E1B2C]">
            <Head title={t('Mon QR code')}>
                <meta name="robots" content="noindex" />
            </Head>
            <p className="font-mono text-xs tracking-widest text-[#4E6600] uppercase">Arena Padel Club</p>
            <h1 className="text-2xl font-extrabold">
                {court} · {date} · {time}
            </h1>
            {qr ? (
                <>
                    <img src={qr} alt={t('QR code d’accès au terrain')} className="size-64 rounded-lg border bg-white p-2" />
                    {window && <p className="text-sm">{t('Valable de :window, à présenter à la borne.', { window })}</p>}
                    {used && <p className="text-sm font-semibold">{t('Ce QR code a déjà été utilisé.')}</p>}
                </>
            ) : (
                <p role="alert" className="rounded-lg bg-[#FCE6E8] p-4 text-[#9E2833]">
                    {t('Cette réservation est annulée : ce QR code ne donne plus accès au terrain.')}
                </p>
            )}
        </main>
    );
}
