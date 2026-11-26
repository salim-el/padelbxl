import LanguageSwitcher from '@/components/language-switcher';
import { useT } from '@/lib/i18n';
import { euros } from '@/lib/money';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarDays, CreditCard, QrCode, Users } from 'lucide-react';

type Price = { name: string; start: string; end: string; price_cents: number; sport?: string | null };

const NAVY = 'bg-[#0E1B2C] text-[#F7F6F1]';
const CTA =
    'inline-flex items-center justify-center rounded-lg bg-[#C8E64A] px-6 py-3 font-bold text-[#0E1B2C] transition hover:bg-[#b5d23c] focus-visible:ring-2 focus-visible:ring-[#C8E64A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0E1B2C] focus-visible:outline-none';
const GHOST =
    'inline-flex items-center justify-center rounded-lg border border-[#F7F6F1]/40 px-6 py-3 font-semibold text-[#F7F6F1] transition hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-[#C8E64A] focus-visible:outline-none';

// shortcut: copie de Court::PLAYERS et des durées de créneau ; à passer par la route si un sport s'ajoute.
const SPORTS: Record<string, { label: string; players: number; duration: string }> = {
    padel: { label: 'Padel', players: 4, duration: '90 minutes' },
    foot5: { label: 'Foot à 5', players: 10, duration: '1 heure' },
};

// Photo en deux tailles : le navigateur choisit la plus légère qui suffit à l'écran.
function Photo({
    name,
    alt,
    className = '',
    priority = false,
    sizes = '100vw',
}: {
    name: string;
    alt: string;
    className?: string;
    priority?: boolean;
    sizes?: string;
}) {
    return (
        <img
            src={`/images/${name}-1600.webp`}
            srcSet={`/images/${name}-800.webp 800w, /images/${name}-1600.webp 1600w`}
            sizes={sizes}
            width={1600}
            height={900}
            alt={alt}
            loading={priority ? 'eager' : 'lazy'}
            fetchPriority={priority ? 'high' : 'auto'}
            decoding="async"
            className={className}
        />
    );
}

function Step({ n, icon: Icon, title, text }: { n: number; icon: typeof CalendarDays; title: string; text: string }) {
    const t = useT();

    return (
        <li className="flex flex-col rounded-2xl border border-[#DAD7CC] bg-white p-6 shadow-sm">
            <div className="flex items-center gap-3">
                <span
                    className="flex size-10 items-center justify-center rounded-full bg-[#0E1B2C] text-lg font-extrabold text-[#C8E64A]"
                    aria-hidden
                >
                    {n}
                </span>
                <Icon className="size-7 text-[#1D5C7A]" aria-hidden />
            </div>
            <h3 className="mt-4 text-lg font-bold">{t(title)}</h3>
            <p className="mt-1 text-[#5F6B78]">{t(text)}</p>
        </li>
    );
}

export default function Home({ prices }: { prices: Price[] }) {
    const { auth } = usePage<SharedData>().props;
    const t = useT();
    const from = Math.min(...prices.map((p) => p.price_cents));
    // Tarifs rangés par sport, padel d'abord.
    const groups = prices.reduce<Record<string, Price[]>>((acc, p) => {
        (acc[p.sport ?? 'padel'] ??= []).push(p);
        return acc;
    }, {});
    const sports = Object.keys(SPORTS).filter((s) => groups[s]);

    return (
        <>
            <Head title={t('Padel et foot à 5 à Anderlecht')}>
                <meta
                    name="description"
                    content={t(
                        'Six terrains de padel et quatre terrains de foot à 5 couverts à Anderlecht. Réservation en ligne, prix partagé entre joueurs, accès par QR code.',
                    )}
                />
            </Head>
            <div className="min-h-screen bg-[#F7F6F1] text-[#0E1B2C]">
                <a
                    href="#contenu"
                    className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"
                >
                    {t('Aller au contenu')}
                </a>

                <header className={NAVY}>
                    <nav
                        aria-label={t('Navigation principale')}
                        className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4"
                    >
                        <Link href="/" aria-label={t('ArenaBxl, accueil')}>
                            <img src="/images/logo-fond-sombre.webp" alt="ArenaBxl" className="h-12 w-auto" />
                        </Link>
                        <ul className="hidden items-center gap-6 text-sm font-semibold md:flex">
                            <li>
                                <a href="#comment" className="hover:text-[#C8E64A]">
                                    {t('Comment ça marche')}
                                </a>
                            </li>
                            <li>
                                <a href="#tarifs" className="hover:text-[#C8E64A]">
                                    {t('Tarifs')}
                                </a>
                            </li>
                            <li>
                                <Link href="/planning" className="hover:text-[#C8E64A]">
                                    {t('Planning')}
                                </Link>
                            </li>
                        </ul>
                        <div className="flex items-center gap-3">
                            <LanguageSwitcher />
                            {auth.user ? (
                                <Link href="/bookings" className="text-sm font-semibold hover:text-[#C8E64A]">
                                    {t('Mon espace')}
                                </Link>
                            ) : (
                                <>
                                    <Link href="/login" className="text-sm font-semibold hover:text-[#C8E64A]">
                                        {t('Connexion')}
                                    </Link>
                                    <Link
                                        href="/register"
                                        className="rounded-lg border border-[#F7F6F1]/40 px-4 py-2 text-sm font-semibold transition hover:bg-white/10"
                                    >
                                        {t('Inscription')}
                                    </Link>
                                </>
                            )}
                            <Link href="/planning" className={`${CTA} px-4 py-2 text-sm`}>
                                {t('Réserver')}
                            </Link>
                        </div>
                    </nav>
                </header>

                <main id="contenu">
                    <section className={`${NAVY} relative overflow-hidden`}>
                        <Photo name="hero" alt="" priority className="absolute inset-0 h-full w-full object-cover" />
                        <div className="absolute inset-0 bg-gradient-to-r from-[#0E1B2C] via-[#0E1B2C]/85 to-[#0E1B2C]/20" aria-hidden />
                        <div className="relative mx-auto max-w-6xl px-4 pt-20 pb-32 md:pt-28 md:pb-40">
                            <div className="max-w-2xl">
                                <p className="font-mono text-sm tracking-widest text-[#C8E64A] uppercase">{t('Padel & foot à 5 · Anderlecht')}</p>
                                <h1 className="mt-3 text-4xl leading-tight font-extrabold uppercase md:text-6xl">
                                    {t('Le padel et le foot,')}
                                    <br />
                                    <span className="text-[#C8E64A]">{t('sans attendre.')}</span>
                                </h1>
                                <p className="mt-5 max-w-xl text-lg text-[#F7F6F1]/85">
                                    {t(
                                        'Six terrains de padel et quatre terrains de foot à 5, couverts, à Anderlecht. Réservez en ligne, partagez le prix entre joueurs et entrez avec votre QR code.',
                                    )}
                                </p>
                                <div className="mt-8 flex flex-wrap gap-3">
                                    <Link href="/planning" className={CTA}>
                                        {t('Réserver un terrain')}
                                    </Link>
                                    <a href="#tarifs" className={GHOST}>
                                        {t('Voir les tarifs')}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section aria-label={t('Le club en chiffres')} className="relative z-10 mx-auto -mt-10 max-w-6xl px-4">
                        <dl className="grid grid-cols-2 gap-3 rounded-2xl bg-white p-6 shadow-lg md:grid-cols-4">
                            {[
                                ['10', 'terrains couverts'],
                                ['24 h', 'annulation gratuite avant le match'],
                                ['9 h – 23 h', 'tous les jours'],
                                [euros(from), 'le créneau, dès'],
                            ].map(([value, label]) => (
                                <div key={label} className="text-center">
                                    <dt className="sr-only">{label}</dt>
                                    <dd className="text-2xl font-extrabold text-[#0E1B2C] md:text-3xl">{t(value)}</dd>
                                    <dd className="text-sm text-[#5F6B78]">{t(label)}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <section id="comment" className="mx-auto max-w-6xl px-4 py-20">
                        <h2 className="text-3xl font-extrabold uppercase">{t('Comment ça marche')}</h2>
                        <p className="mt-2 max-w-2xl text-[#5F6B78]">
                            {t('Quatre étapes, du planning au terrain. Le prix se partage entre tous les joueurs.')}
                        </p>
                        <ol className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Step
                                n={1}
                                icon={CalendarDays}
                                title="Choisissez un créneau"
                                text="Le planning montre les terrains libres en temps réel, sans compte. Un créneau dure 90 minutes au padel, une heure au foot."
                            />
                            <Step
                                n={2}
                                icon={Users}
                                title="Partagez le prix"
                                text="Invitez vos partenaires par e-mail : jusqu’à 3 au padel, 9 au foot. Chacun paie sa part en ligne et reçoit sa propre facture."
                            />
                            <Step
                                n={3}
                                icon={CreditCard}
                                title="Payez votre part"
                                text="Le créneau est bloqué 10 minutes pour vous. Annulation remboursée jusqu’à 24 h avant le match."
                            />
                            <Step
                                n={4}
                                icon={QrCode}
                                title="Entrez avec votre QR code"
                                text="Chaque joueur reçoit son propre QR code. Il ouvre le terrain 15 minutes avant le début : pas de clé, pas d’attente à l’accueil."
                            />
                        </ol>
                    </section>

                    <section id="tarifs" className="bg-white py-20">
                        <div className="mx-auto max-w-6xl px-4">
                            <h2 className="text-3xl font-extrabold uppercase">{t('Tarifs')}</h2>
                            <p className="mt-2 max-w-2xl text-[#5F6B78]">
                                {t(
                                    'Prix par terrain et par créneau, TVA de 6 % comprise. Le prix se partage entre les joueurs : chacun paie sa part.',
                                )}
                            </p>
                            <div className="mt-8 grid gap-6 md:grid-cols-2">
                                {sports.map((sport) => {
                                    const { label, players, duration } = SPORTS[sport];

                                    return (
                                        <article key={sport} className="overflow-hidden rounded-2xl border border-[#DAD7CC]">
                                            <header className="flex flex-wrap items-baseline justify-between gap-2 bg-[#0E1B2C] px-6 py-4 text-[#F7F6F1]">
                                                <h3 className="text-xl font-extrabold uppercase">{t(label)}</h3>
                                                <p className="text-sm text-[#F7F6F1]/80">
                                                    {players} {t('joueurs')} · {t(duration)}
                                                </p>
                                            </header>
                                            <ul className="divide-y divide-[#DAD7CC] px-6">
                                                {groups[sport].map((p) => (
                                                    <li key={p.name} className="flex items-center justify-between gap-4 py-4">
                                                        <div>
                                                            <p className="font-semibold">{t(p.name)}</p>
                                                            <p className="text-sm text-[#5F6B78]">
                                                                {p.start} – {p.end}
                                                            </p>
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="text-2xl font-extrabold">{euros(p.price_cents)}</p>
                                                            <p className="text-sm font-semibold text-[#1D5C7A]">
                                                                {t('soit')} {euros(Math.floor(p.price_cents / players))} {t('par joueur')}
                                                            </p>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        </article>
                                    );
                                })}
                            </div>
                            <p className="mt-6 flex items-start gap-3 rounded-xl bg-[#C8E64A]/25 p-4 text-[#0E1B2C]">
                                <Users className="mt-0.5 size-5 shrink-0" aria-hidden />
                                <span>
                                    {t(
                                        'L’organisateur invite ses partenaires. Chacun paie sa part en ligne et reçoit sa facture et son QR code. À 24 h du match, l’organisateur règle les parts encore impayées.',
                                    )}
                                </span>
                            </p>
                        </div>
                    </section>

                    <section className={`${NAVY} relative overflow-hidden py-24 text-center`}>
                        <Photo name="joueurs" alt="" className="absolute inset-0 h-full w-full object-cover" />
                        <div className="absolute inset-0 bg-[#0E1B2C]/75" aria-hidden />
                        <div className="relative">
                            <h2 className="text-3xl font-extrabold uppercase md:text-4xl">{t('Prêt à jouer ?')}</h2>
                            <p className="mt-3 text-[#F7F6F1]/80">{t('Les créneaux du soir partent vite. Réservez en moins d’une minute.')}</p>
                            <Link href="/planning" className={`${CTA} mt-6`}>
                                {t('Voir les créneaux libres')}
                            </Link>
                        </div>
                    </section>
                </main>

                <footer className="bg-[#0A1420] py-8 text-sm text-[#F7F6F1]/70">
                    <div className="mx-auto flex max-w-6xl flex-wrap justify-between gap-4 px-4">
                        <p>{t('Arena Padel Club · Anderlecht, Bruxelles')}</p>
                        <nav aria-label={t('Liens du pied de page')} className="flex flex-wrap gap-4">
                            <Link href="/planning" className="hover:text-[#C8E64A]">
                                {t('Planning')}
                            </Link>
                            <Link href={auth.user ? '/bookings' : '/login'} className="hover:text-[#C8E64A]">
                                {auth.user ? t('Mes réservations') : t('Connexion')}
                            </Link>
                            <Link href="/conditions-generales" className="hover:text-[#C8E64A]">
                                {t('Conditions générales')}
                            </Link>
                            <Link href="/confidentialite" className="hover:text-[#C8E64A]">
                                {t('Confidentialité')}
                            </Link>
                            <Link href="/contact" className="hover:text-[#C8E64A]">
                                {t('Contact')}
                            </Link>
                        </nav>
                    </div>
                </footer>
            </div>
        </>
    );
}
