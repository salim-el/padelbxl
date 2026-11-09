import { BackButton } from '@/components/back-button';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { euros, GAME_MODES, ucfirst } from '@/lib/money';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, ShieldAlert, ShieldCheck, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';

type Booking = {
    id: string;
    court: string;
    is_arena: boolean;
    date: string;
    time: string;
    price_cents: number;
    vat_rate: number;
    hold_expires_at: string | null;
    free_cancellation_until: string;
    refundable: boolean;
    arcade_price_cents: number | null;
};

type Product = { id: number; name: string; kind: 'rental' | 'sale'; price_cents: number };

export default function Checkout({ booking, products }: { booking: Booking; products: Product[] }) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm<{
        accept_terms: boolean;
        game_mode: string | null;
        items: Record<number, number>;
        guests: string[];
        open_spots: number;
        accept_guarantee: boolean;
    }>({
        accept_terms: false,
        game_mode: 'classic',
        items: {},
        guests: ['', '', ''],
        open_spots: 0,
        accept_guarantee: false,
    });
    const pageErrors = usePage<{ errors: Record<string, string> }>().props.errors;
    const [left, setLeft] = useState(0);
    const arcade = data.game_mode !== 'classic' && booking.arcade_price_cents !== null;
    const court = arcade ? (booking.arcade_price_cents as number) : booking.price_cents;
    const extras = products.reduce((sum, p) => sum + (data.items[p.id] ?? 0) * p.price_cents, 0);
    // Même calcul que le serveur : part égale, les centimes restants à l'organisateur.
    const emails = data.guests.filter((g) => g.trim() !== '').length;
    const partners = emails + data.open_spots;
    const maxOpen = 3 - emails;
    const share = Math.floor(court / (partners + 1));
    const myShare = court - share * partners;
    const total = myShare + extras;
    const setGuest = (i: number, email: string) =>
        setData(
            'guests',
            data.guests.map((g, j) => (j === i ? email : g)),
        );
    const setQty = (id: number, q: number) => setData('items', { ...data.items, [id]: Math.max(0, Math.min(4, q)) });

    useEffect(() => {
        const tick = () => setLeft(Math.max(0, Math.floor((new Date(booking.hold_expires_at ?? 0).getTime() - Date.now()) / 1000)));
        tick();
        const t = setInterval(tick, 1000);
        return () => clearInterval(t);
    }, [booking.hold_expires_at]);

    const urgent = left < 120;

    return (
        <AppLayout breadcrumbs={[{ title: t('Paiement'), href: '#' }]}>
            <Head title={t('Paiement')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <BackButton href="/planning">{t('Planning')}</BackButton>
                <PageHeader eyebrow={t('Dernière étape')} title={t('Paiement')}>
                    <span
                        aria-live="polite"
                        className={`inline-flex items-center gap-2 rounded-full px-3 py-1.5 font-mono text-sm font-bold ${urgent ? 'bg-[#CC3342] text-white' : 'bg-[#C8E64A] text-[#0E1B2C]'}`}
                    >
                        <Timer className="size-4" aria-hidden />
                        {Math.floor(left / 60)}:{String(left % 60).padStart(2, '0')}
                        <span className="sr-only">{t('avant la libération du créneau')}</span>
                    </span>
                </PageHeader>

                <section className="bg-card rounded-2xl border p-6 shadow-sm">
                    <h2 className="text-xl font-bold">{booking.court}</h2>
                    <ul className="mt-3 space-y-2 text-sm">
                        <li className="flex items-center gap-2">
                            <CalendarDays className="size-4 text-[#1D5C7A]" aria-hidden /> {ucfirst(booking.date)}
                        </li>
                        <li className="flex items-center gap-2">
                            <Clock className="size-4 text-[#1D5C7A]" aria-hidden /> {booking.time}
                        </li>
                        {booking.refundable ? (
                            <li className="flex items-center gap-2">
                                <ShieldCheck className="size-4 text-[#4E6600]" aria-hidden />{' '}
                                {t('Annulation gratuite jusqu’au :date', { date: booking.free_cancellation_until })}
                            </li>
                        ) : (
                            <li className="flex items-center gap-2 font-semibold text-[#9E2833]">
                                <ShieldAlert className="size-4" aria-hidden />{' '}
                                {t('Match dans moins de 24 h : une annulation ne sera pas remboursée.')}
                            </li>
                        )}
                    </ul>
                    <div className="mt-5 flex items-end justify-between border-t border-dashed pt-4">
                        <span className="text-sm text-[#5F6B78]">{t('Total TTC, TVA :rate % comprise', { rate: booking.vat_rate })}</span>
                        <span className="text-3xl font-extrabold">{euros(total)}</span>
                    </div>
                </section>

                <p className="rounded-lg bg-[#E6ECF2] p-3 text-sm text-[#17304A]">
                    {t('Version alpha : le paiement est simulé. Aucune carte n’est débitée.')}
                </p>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(`/bookings/${booking.id}/pay`);
                    }}
                    className="flex flex-col gap-3"
                >
                    {booking.arcade_price_cents !== null && (
                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-2 font-bold">{t('Choisissez votre mode de jeu')}</legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {Object.entries(GAME_MODES).map(([value, mode]) => (
                                    <label
                                        key={value}
                                        className={`cursor-pointer rounded-xl border-2 p-3 text-sm transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#1D5C7A] ${
                                            data.game_mode === value
                                                ? 'border-[#C8E64A] bg-[#0E1B2C] text-[#F7F6F1]'
                                                : 'bg-card border-[#DAD7CC] hover:border-[#C8E64A]'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="game_mode"
                                            value={value}
                                            checked={data.game_mode === value}
                                            onChange={() => setData('game_mode', value)}
                                            className="sr-only"
                                        />
                                        <span className={`flex justify-between gap-2 font-bold ${data.game_mode === value ? 'text-[#C8E64A]' : ''}`}>
                                            {t(mode.label)}
                                            <span className="text-xs">
                                                {value === 'classic' || booking.arcade_price_cents === null
                                                    ? t('inclus')
                                                    : `+${euros(booking.arcade_price_cents - booking.price_cents)}`}
                                            </span>
                                        </span>
                                        <span className="mt-1 block text-xs opacity-80">{t(mode.text)}</span>
                                    </label>
                                ))}
                            </div>
                            {errors.game_mode && <p className="text-sm text-[#9E2833]">{t('Choisissez un des modes de jeu proposés.')}</p>}
                        </fieldset>
                    )}
                    {products.length > 0 && (
                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-2 font-bold">{t('Matériel')}</legend>
                            {products.map((p) => (
                                <div key={p.id} className="bg-card flex items-center justify-between gap-3 rounded-xl border p-3 text-sm">
                                    <span>
                                        <span className="font-semibold">{p.name}</span>
                                        <span className="text-muted-foreground block text-xs">
                                            {euros(p.price_cents)} {t(p.kind === 'rental' ? 'par partie' : 'l’unité')} · {t('TVA 21 %')}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setQty(p.id, (data.items[p.id] ?? 0) - 1)}
                                            className="size-8 rounded-md border font-bold"
                                            aria-label={t('Retirer : :name', { name: p.name })}
                                        >
                                            −
                                        </button>
                                        <span className="w-6 text-center font-semibold" aria-live="polite">
                                            {data.items[p.id] ?? 0}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => setQty(p.id, (data.items[p.id] ?? 0) + 1)}
                                            className="size-8 rounded-md border font-bold"
                                            aria-label={t('Ajouter : :name', { name: p.name })}
                                        >
                                            +
                                        </button>
                                    </span>
                                </div>
                            ))}
                        </fieldset>
                    )}
                    {booking.refundable ? (
                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1 font-bold">{t('Partager le prix (facultatif)')}</legend>
                            <p className="text-sm text-[#5F6B78]">
                                {t('Chaque partenaire reçoit un e-mail et paie sa part. Vous ne payez que la vôtre.')}
                            </p>
                            {data.guests.map((g, i) => (
                                <label key={i} className="flex flex-col gap-1 text-sm">
                                    <span className="sr-only">{t('E-mail du partenaire :n', { n: i + 1 })}</span>
                                    <input
                                        type="email"
                                        autoComplete="off"
                                        value={g}
                                        onChange={(e) => setGuest(i, e.target.value)}
                                        placeholder={t('E-mail du partenaire :n', { n: i + 1 })}
                                        className="bg-card rounded-lg border px-3 py-2"
                                    />
                                    {errors[`guests.${i}` as keyof typeof errors] && (
                                        <span className="text-[#9E2833]">{t('Cette adresse e-mail n’est pas valable ou est en double.')}</span>
                                    )}
                                </label>
                            ))}
                            <div className="bg-card flex items-center justify-between gap-3 rounded-xl border p-3 text-sm">
                                <span>
                                    <span className="font-semibold">{t('Places ouvertes au club')}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {t('Un autre membre peut prendre la place et payer sa part.')}
                                    </span>
                                </span>
                                <span className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setData('open_spots', Math.max(0, data.open_spots - 1))}
                                        className="size-8 rounded-md border font-bold"
                                        aria-label={t('Une place ouverte de moins')}
                                    >
                                        −
                                    </button>
                                    <span className="w-6 text-center font-semibold" aria-live="polite">
                                        {data.open_spots}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => setData('open_spots', Math.min(maxOpen, data.open_spots + 1))}
                                        className="size-8 rounded-md border font-bold"
                                        aria-label={t('Une place ouverte de plus')}
                                    >
                                        +
                                    </button>
                                </span>
                            </div>
                            {partners > 3 && (
                                <p className="text-sm text-[#9E2833]">{t('Invitez au plus trois autres joueurs, sans vous compter.')}</p>
                            )}
                            {partners > 0 && (
                                <>
                                    <p className="rounded-lg bg-[#E3F0F7] p-3 text-sm text-[#17304A]">
                                        {t('Part de chaque partenaire : :share. Votre part : :mine.', { share: euros(share), mine: euros(myShare) })}
                                    </p>
                                    <label className="flex items-start gap-3 text-sm">
                                        <input
                                            type="checkbox"
                                            className="mt-0.5 size-4 accent-[#1D5C7A]"
                                            checked={data.accept_guarantee}
                                            onChange={(e) => setData('accept_guarantee', e.target.checked)}
                                        />
                                        {t('Ma carte sert de garantie : les parts encore impayées 24 h avant le match me seront débitées.')}
                                    </label>
                                    {errors.accept_guarantee && <p className="text-sm text-[#9E2833]">{errors.accept_guarantee}</p>}
                                </>
                            )}
                        </fieldset>
                    ) : (
                        <p className="text-sm text-[#5F6B78]">{t('Le prix ne se partage que pour un match dans plus de 24 h.')}</p>
                    )}
                    {pageErrors.booking && (
                        <p role="alert" className="rounded-lg border border-[#9E2833]/30 bg-[#FCE6E8] p-3 text-sm text-[#9E2833]">
                            {pageErrors.booking}
                        </p>
                    )}
                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5 size-4 accent-[#1D5C7A]"
                            checked={data.accept_terms}
                            onChange={(e) => setData('accept_terms', e.target.checked)}
                        />
                        {t('J’accepte les conditions générales et la règle d’annulation de 24 heures.')}
                    </label>
                    {errors.accept_terms && <p className="text-sm text-[#9E2833]">{t('Vous devez accepter les conditions pour réserver.')}</p>}
                    <Button type="submit" size="lg" className="h-12 text-base font-bold" disabled={processing || left === 0}>
                        {t('Payer :amount', { amount: euros(total) })}
                    </Button>
                    {left === 0 && (
                        <p className="text-sm text-[#9E2833]">{t('Les dix minutes sont écoulées. Revenez au planning pour choisir un créneau.')}</p>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}
