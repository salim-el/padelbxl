import { BackButton } from '@/components/back-button';
import { PageHeader } from '@/components/page-header';
import TermsLink from '@/components/terms-link';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { SPORT_LABELS, euros, ucfirst } from '@/lib/money';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, ShieldAlert, ShieldCheck, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';

type Booking = {
    id: string;
    court: string;
    date: string;
    time: string;
    price_cents: number;
    vat_rate: number;
    hold_expires_at: string | null;
    free_cancellation_until: string;
    refundable: boolean;
};

type Product = { id: number; name: string; kind: 'rental' | 'sale'; price_cents: number };

export default function Checkout({
    booking,
    products,
    sport,
    max_guests,
    my_level,
    credit_cents,
}: {
    booking: Booking;
    products: Product[];
    sport: string;
    max_guests: number;
    my_level: number | null;
    credit_cents: number;
}) {
    const t = useT();
    // F9 : avec les clés Stripe, le paiement passe par la page de Stripe ; sinon il reste simulé.
    const { stripe } = usePage<{ stripe: boolean }>().props;
    const { data, setData, post, processing, errors } = useForm<{
        accept_terms: boolean;
        items: Record<number, number>;
        guests: string[];
        open_spots: number;
        level_min: number;
        level_max: number;
        accept_guarantee: boolean;
    }>({
        accept_terms: false,
        items: {},
        // Une case par autre joueur du sport : 3 au padel, 9 au foot à 5.
        guests: Array<string>(max_guests).fill(''),
        open_spots: 0,
        level_min: Math.max(1, (my_level ?? 4) - 1),
        level_max: Math.min(7, (my_level ?? 4) + 1),
        accept_guarantee: false,
    });
    const pageErrors = usePage<{ errors: Record<string, string> }>().props.errors;
    const [left, setLeft] = useState(0);
    const court = booking.price_cents;
    const extras = products.reduce((sum, p) => sum + (data.items[p.id] ?? 0) * p.price_cents, 0);
    // Même calcul que le serveur : part égale, les centimes restants à l'organisateur.
    const emails = data.guests.filter((g) => g.trim() !== '').length;
    const partners = emails + data.open_spots;
    const maxOpen = max_guests - emails;
    const share = Math.floor(court / (partners + 1));
    // Part de chacun quand le terrain est complet : 4 joueurs au padel, 10 au foot à 5.
    const fullShare = Math.floor(court / (max_guests + 1));
    const myShare = court - share * partners;
    const total = myShare + extras;
    // F15 : même règle que le serveur, l'avoir paie d'abord et la carte le reste.
    const byCredit = Math.min(credit_cents, total);
    const byCard = total - byCredit;
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
                    <p className="text-muted-foreground text-sm">
                        {t(SPORT_LABELS[sport])} · {t(':n joueurs par terrain', { n: max_guests + 1 })}
                    </p>
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
                    {byCredit > 0 && (
                        <p className="mt-3 rounded-lg bg-[#F4F9E1] p-3 text-sm text-[#17304A]">
                            {t('Votre avoir paie :credit. Reste à payer par carte : :card.', { credit: euros(byCredit), card: euros(byCard) })}
                        </p>
                    )}
                </section>

                <p className="rounded-lg bg-[#E6ECF2] p-3 text-sm text-[#17304A]">
                    {stripe
                        ? t('Paiement sécurisé par Stripe, en mode test : aucune carte réelle n’est débitée.')
                        : t('Version alpha : le paiement est simulé. Aucune carte n’est débitée.')}
                </p>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(`/bookings/${booking.id}/pay`);
                    }}
                    className="flex flex-col gap-3"
                >
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
                                {t('Chaque partenaire reçoit un e-mail et paie sa part. Vous ne payez que la vôtre.')}{' '}
                                {t('À :n joueurs, chacun paie :share.', { n: max_guests + 1, share: euros(fullShare) })}
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
                            {/* L'appel aux membres suit l'échelle de niveau du padel (1 à 7). Au foot, aucune place ne s'ouvre : le niveau reste caché. */}
                            {sport === 'padel' && (
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
                            )}
                            {data.open_spots > 0 && (
                                <fieldset className="bg-card flex flex-wrap items-center gap-3 rounded-xl border p-3 text-sm">
                                    <legend className="sr-only">{t('Niveau demandé')}</legend>
                                    <span className="font-semibold">{t('Niveau demandé')}</span>
                                    <label className="flex items-center gap-1">
                                        {t('de')}
                                        <select
                                            value={data.level_min}
                                            onChange={(e) => setData('level_min', Number(e.target.value))}
                                            className="rounded-md border px-2 py-1"
                                        >
                                            {[1, 2, 3, 4, 5, 6, 7].map((l) => (
                                                <option key={l} value={l}>
                                                    {l}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="flex items-center gap-1">
                                        {t('à')}
                                        <select
                                            value={data.level_max}
                                            onChange={(e) => setData('level_max', Number(e.target.value))}
                                            className="rounded-md border px-2 py-1"
                                        >
                                            {[1, 2, 3, 4, 5, 6, 7]
                                                .filter((l) => l >= data.level_min)
                                                .map((l) => (
                                                    <option key={l} value={l}>
                                                        {l}
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                    <span className="text-muted-foreground w-full text-xs">
                                        {t('Les joueurs de ce niveau, libres à cette heure, reçoivent un e-mail.')}
                                    </span>
                                    {(pageErrors.level_min || pageErrors.level_max) && (
                                        <span role="alert" className="w-full text-[#9E2833]">
                                            {pageErrors.level_min ?? pageErrors.level_max}
                                        </span>
                                    )}
                                </fieldset>
                            )}
                            {partners > max_guests && (
                                <p className="text-sm text-[#9E2833]">
                                    {t('Invitez au plus :count autres joueurs, sans vous compter.', { count: max_guests })}
                                </p>
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
                                        <span>
                                            {t('Ma carte sert de garantie : les parts encore impayées 24 h avant le match me seront débitées.')}{' '}
                                            {t(
                                                'Si j’annule à moins de 24 h, je paie tout le terrain : les parts déjà réglées par mes partenaires leur sont rendues et me sont débitées.',
                                            )}
                                        </span>
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
                    <TermsLink />
                    {errors.accept_terms && <p className="text-sm text-[#9E2833]">{t('Vous devez accepter les conditions pour réserver.')}</p>}
                    <Button type="submit" size="lg" className="h-12 text-base font-bold" disabled={processing || left === 0}>
                        {byCard === 0 ? t('Payer avec mon avoir') : t('Payer :amount', { amount: euros(byCard) })}
                    </Button>
                    {left === 0 && (
                        <p className="text-sm text-[#9E2833]">{t('Les dix minutes sont écoulées. Revenez au planning pour choisir un créneau.')}</p>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}
