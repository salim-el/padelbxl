import { Button } from '@/components/ui/button';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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
    // F18 : la borne simulée lit le QR avec la caméra du téléphone ou de la tablette.
    const video = useRef<HTMLVideoElement>(null);
    const [camera, setCamera] = useState<'off' | 'on' | 'unavailable'>('off');
    useEffect(() => {
        if (camera !== 'on') return;
        type Detector = { detect: (v: HTMLVideoElement) => Promise<{ rawValue: string }[]> };
        const Ctor = (window as unknown as { BarcodeDetector?: new (o: { formats: string[] }) => Detector }).BarcodeDetector;
        if (!Ctor || !navigator.mediaDevices) {
            setCamera('unavailable');
            return;
        }
        const detector = new Ctor({ formats: ['qr_code'] });
        let stream: MediaStream | null = null;
        let timer = 0;
        navigator.mediaDevices
            .getUserMedia({ video: { facingMode: 'environment' } })
            .then((s) => {
                stream = s;
                if (video.current) {
                    video.current.srcObject = s;
                    void video.current.play();
                }
                timer = window.setInterval(async () => {
                    if (!video.current) return;
                    const [code] = await detector.detect(video.current);
                    if (code?.rawValue) {
                        window.clearInterval(timer);
                        setCamera('off');
                        router.post('/kiosk', { court_id: data.court_id, token: code.rawValue });
                    }
                }, 400);
            })
            .catch(() => setCamera('unavailable'));
        return () => {
            window.clearInterval(timer);
            stream?.getTracks().forEach((t) => t.stop());
        };
    }, [camera, data.court_id]);

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
            <div className="flex w-full max-w-md flex-col items-center gap-3">
                {camera === 'on' ? (
                    <video ref={video} muted playsInline className="w-full rounded-xl border-2 border-[#C8E64A]" aria-label="Image de la caméra" />
                ) : (
                    <Button type="button" onClick={() => setCamera('on')} className="w-full bg-[#C8E64A] font-bold text-[#0E1B2C] hover:bg-[#b5d23c]">
                        Scanner un QR code avec la caméra
                    </Button>
                )}
                {camera === 'unavailable' && (
                    <p className="text-sm text-[#F7F6F1]/80">Caméra indisponible sur cet appareil : saisissez le code ci-dessous.</p>
                )}
            </div>
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
