import { PageHeader } from '@/components/page-header';
import TermsLink from '@/components/terms-link';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { euros, ucfirst } from '@/lib/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, Users } from 'lucide-react';
import { useState } from 'react';

type Match = {
    id: string;
    court: string;
    date: string;
    time: string;
    share_cents: number;
    free: number;
    joined: boolean;
    waiting: boolean;
};

export default function OpenMatches({ matches }: { matches: Match[] }) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [accepted, setAccepted] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const send = (method: 'post' | 'delete', url: string, data = {}) =>
        router.visit(url, { method, data, preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <AppLayout breadcrumbs={[{ title: t('Parties ouvertes'), href: '/open-matches' }]}>
            <Head title={t('Parties ouvertes')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <PageHeader eyebrow={t('Compléter une partie')} title={t('Parties ouvertes')} />
                <p className="text-sm text-[#5F6B78]">
                    {t('Des membres cherchent des joueurs. Vous payez votre part et recevez votre propre QR code.')}
                </p>

                {errors.booking && (
                    <p role="alert" className="rounded-lg border border-[#9E2833]/30 bg-[#FCE6E8] p-3 text-sm text-[#9E2833]">
                        {errors.booking}
                    </p>
                )}

                {matches.length === 0 && (
                    <p className="bg-card rounded-xl border p-6 text-center text-sm">{t('Aucune partie ouverte pour le moment.')}</p>
                )}

                <ul className="flex flex-col gap-3">
                    {matches.map((m) => (
                        <li key={m.id} className="bg-card rounded-2xl border p-5 shadow-sm">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-bold">{m.court}</h2>
                                    <ul className="mt-2 space-y-1 text-sm">
                                        <li className="flex items-center gap-2">
                                            <CalendarDays className="size-4 text-[#1D5C7A]" aria-hidden /> {ucfirst(m.date)}
                                        </li>
                                        <li className="flex items-center gap-2">
                                            <Clock className="size-4 text-[#1D5C7A]" aria-hidden /> {m.time}
                                        </li>
                                        <li className="flex items-center gap-2">
                                            <Users className="size-4 text-[#1D5C7A]" aria-hidden />
                                            {m.free === 0 ? t('Complet') : m.free === 1 ? t('1 place libre') : t(':n places libres', { n: m.free })}
                                        </li>
                                    </ul>
                                </div>
                                <span className="text-right">
                                    <span className="block text-2xl font-extrabold">{euros(m.share_cents)}</span>
                                    <span className="text-xs text-[#5F6B78]">{t('votre part, TVA comprise')}</span>
                                </span>
                            </div>

                            <div className="mt-4 border-t border-dashed pt-4">
                                {m.joined ? (
                                    <Link href={`/bookings/${m.id}/share`} className="font-semibold text-[#1D5C7A] underline">
                                        {t('Vous jouez dans cette partie. Voir mon QR code')}
                                    </Link>
                                ) : m.free > 0 ? (
                                    <div className="flex flex-col gap-3">
                                        <label className="flex items-start gap-3 text-sm">
                                            <input
                                                type="checkbox"
                                                className="mt-0.5 size-4 accent-[#1D5C7A]"
                                                checked={accepted === m.id}
                                                onChange={(e) => setAccepted(e.target.checked ? m.id : null)}
                                            />
                                            {t('J’accepte les conditions générales et la règle d’annulation de 24 heures.')}
                                        </label>
                                        <TermsLink />
                                        <Button
                                            disabled={busy || accepted !== m.id}
                                            onClick={() => send('post', `/open-matches/${m.id}/join`, { accept_terms: true })}
                                        >
                                            {t('Prendre la place et payer :amount', { amount: euros(m.share_cents) })}
                                        </Button>
                                    </div>
                                ) : m.waiting ? (
                                    <Button variant="outline" disabled={busy} onClick={() => send('delete', `/open-matches/${m.id}/waitlist`)}>
                                        {t('Quitter la liste d’attente')}
                                    </Button>
                                ) : (
                                    <Button variant="outline" disabled={busy} onClick={() => send('post', `/open-matches/${m.id}/waitlist`)}>
                                        {t('M’inscrire sur la liste d’attente')}
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
