import { euros } from '@/lib/money';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Ban, CalendarDays, CreditCard, QrCode, Target, Trophy } from 'lucide-react';

type Price = { name: string; start: string; end: string; price_cents: number; is_arena: boolean };

const NAVY = 'bg-[#0E1B2C] text-[#F7F6F1]';
const CTA =
    'inline-flex items-center justify-center rounded-lg bg-[#C8E64A] px-6 py-3 font-bold text-[#0E1B2C] transition hover:bg-[#b5d23c] focus-visible:ring-2 focus-visible:ring-[#C8E64A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0E1B2C] focus-visible:outline-none';
const GHOST =
    'inline-flex items-center justify-center rounded-lg border border-[#F7F6F1]/40 px-6 py-3 font-semibold text-[#F7F6F1] transition hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-[#C8E64A] focus-visible:outline-none';

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

function Step({ icon: Icon, title, text }: { icon: typeof CalendarDays; title: string; text: string }) {
    return (
        <li className="rounded-xl border border-[#DAD7CC] bg-white p-6">
            <Icon className="size-8 text-[#1D5C7A]" aria-hidden />
            <h3 className="mt-3 text-lg font-bold">{title}</h3>
            <p className="mt-1 text-[#5F6B78]">{text}</p>
        </li>
    );
}

export default function Home({ prices }: { prices: Price[] }) {
    const { auth } = usePage<SharedData>().props;
    const from = Math.min(...prices.map((p) => p.price_cents));
    const classic = prices.filter((p) => !p.is_arena);
    const arena = prices.filter((p) => p.is_arena);

    return (
        <>
            <Head title="Padel indoor à Anderlecht">
                <meta
                    name="description"
                    content="Six terrains de padel couverts à Anderlecht, tous jouables en mode arcade avec zones lumineuses. Réservation en ligne, paiement sécurisé, accès par QR code."
                />
            </Head>
            <div className="min-h-screen bg-[#F7F6F1] text-[#0E1B2C]">
                <a
                    href="#contenu"
                    className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"
                >
                    Aller au contenu
                </a>

                <header className={NAVY}>
                    <nav aria-label="Navigation principale" className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4">
                        <Link href="/" aria-label="PadelBxl, accueil">
                            <img src="/images/logo-fond-sombre.webp" alt="PadelBxl" className="h-12 w-auto" />
                        </Link>
                        <ul className="hidden items-center gap-6 text-sm font-semibold md:flex">
                            <li>
                                <a href="#arena" className="hover:text-[#C8E64A]">
                                    Mode arcade
                                </a>
                            </li>
                            <li>
                                <a href="#comment" className="hover:text-[#C8E64A]">
                                    Comment ça marche
                                </a>
                            </li>
                            <li>
                                <a href="#tarifs" className="hover:text-[#C8E64A]">
                                    Tarifs
                                </a>
                            </li>
                            <li>
                                <Link href="/planning" className="hover:text-[#C8E64A]">
                                    Planning
                                </Link>
                            </li>
                        </ul>
                        <div className="flex items-center gap-3">
                            {auth.user ? (
                                <Link href="/bookings" className="text-sm font-semibold hover:text-[#C8E64A]">
                                    Mon espace
                                </Link>
                            ) : (
                                <>
                                    <Link href="/login" className="text-sm font-semibold hover:text-[#C8E64A]">
                                        Connexion
                                    </Link>
                                    <Link
                                        href="/register"
                                        className="rounded-lg border border-[#F7F6F1]/40 px-4 py-2 text-sm font-semibold transition hover:bg-white/10"
                                    >
                                        Inscription
                                    </Link>
                                </>
                            )}
                            <Link href="/planning" className={`${CTA} px-4 py-2 text-sm`}>
                                Réserver
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
                                <p className="font-mono text-sm tracking-widest text-[#C8E64A] uppercase">Nouveau · Sur les 6 terrains</p>
                                <h1 className="mt-3 text-4xl leading-tight font-extrabold uppercase md:text-6xl">
                                    Le padel,
                                    <br />
                                    <span className="text-[#C8E64A]">version arcade.</span>
                                </h1>
                                <p className="mt-5 max-w-xl text-lg text-[#F7F6F1]/85">
                                    Six terrains couverts à Anderlecht. En mode Arcade, des zones s’allument au sol et changent la valeur des points :
                                    point double, faute ou points Arena.
                                </p>
                                <div className="mt-8 flex flex-wrap gap-3">
                                    <Link href="/planning" className={CTA}>
                                        Réserver un terrain
                                    </Link>
                                    <a href="#arena" className={GHOST}>
                                        Découvrir le mode arcade
                                    </a>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section aria-label="Le club en chiffres" className="relative z-10 mx-auto -mt-10 max-w-6xl px-4">
                        <dl className="grid grid-cols-2 gap-3 rounded-2xl bg-white p-6 shadow-lg md:grid-cols-4">
                            {[
                                ['6', 'terrains couverts'],
                                ['4', 'modes de jeu'],
                                ['9 h – 22 h 30', 'tous les jours'],
                                [euros(from), 'le créneau de 90 min, dès'],
                            ].map(([value, label]) => (
                                <div key={label} className="text-center">
                                    <dt className="sr-only">{label}</dt>
                                    <dd className="text-2xl font-extrabold text-[#0E1B2C] md:text-3xl">{value}</dd>
                                    <dd className="text-sm text-[#5F6B78]">{label}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <section id="arena" className="mt-20 bg-[#0A1420] py-20 text-[#F7F6F1]">
                        <div className="mx-auto max-w-6xl px-4">
                            <div className="grid items-center gap-10 md:grid-cols-2">
                                <div>
                                    <p className="font-mono text-sm tracking-widest text-[#C8E64A] uppercase">Mode arcade</p>
                                    <h2 className="mt-2 text-3xl font-extrabold uppercase md:text-4xl">Votre match devient une partie</h2>
                                    <p className="mt-4 max-w-2xl text-[#F7F6F1]/80">
                                        Des bandes LED au pied des parois signalent des zones sur chacun des six terrains. Le jeu reste le padel, avec
                                        des règles de score propres au mode : avant certains points, une zone s’allume au même endroit dans les deux
                                        camps et change la valeur du point.
                                    </p>
                                </div>
                                <Photo
                                    name="arena"
                                    sizes="(min-width: 768px) 50vw, 100vw"
                                    alt="Balle qui rebondit dans une zone lumineuse verte, sur un terrain en mode arcade éclairé par des LED"
                                    className="aspect-video w-full rounded-2xl object-cover shadow-[0_0_40px_rgba(200,230,74,0.25)]"
                                />
                            </div>
                            <ul className="mt-10 grid gap-4 md:grid-cols-3">
                                {[
                                    {
                                        icon: Trophy,
                                        title: 'Zone Or',
                                        text: 'Gagnez le point avec une balle qui a rebondi dans la zone Or adverse : il compte double.',
                                    },
                                    {
                                        icon: Ban,
                                        title: 'Zone Rouge',
                                        text: 'Une balle dont le premier rebond tombe dans la zone Rouge adverse est faute, comme une balle dehors.',
                                    },
                                    {
                                        icon: Target,
                                        title: 'Zone Bleue',
                                        text: 'Un point gagné avec un rebond dans la zone Bleue adverse rapporte des points Arena, sans changer le score.',
                                    },
                                ].map(({ icon: Icon, title, text }) => (
                                    <li key={title} className="rounded-xl border border-[#C8E64A]/30 bg-[#17304A] p-6">
                                        <Icon className="size-8 text-[#C8E64A]" aria-hidden />
                                        <h3 className="mt-3 text-xl font-bold">{title}</h3>
                                        <p className="mt-2 text-[#F7F6F1]/80">{text}</p>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-[#F7F6F1]/80">
                                <span className="flex items-center gap-2">
                                    <span className="inline-block size-3 shrink-0 rounded-sm bg-[#F2C94C]" aria-hidden /> zone Or : point double
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className="inline-block size-3 shrink-0 rounded-sm bg-[#CC3342]" aria-hidden /> zone Rouge : faute
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className="inline-block size-3 shrink-0 rounded-sm bg-[#4A90D9]" aria-hidden /> zone Bleue : points Arena
                                </span>
                            </p>
                            <Link href="/planning" className={`${CTA} mt-8`}>
                                Réserver en mode arcade
                            </Link>
                        </div>
                    </section>

                    <section id="comment" className="mx-auto max-w-6xl px-4 py-20">
                        <h2 className="text-3xl font-extrabold uppercase">Comment ça marche</h2>
                        <ol className="mt-8 grid gap-4 md:grid-cols-3">
                            <Step
                                icon={CalendarDays}
                                title="1. Choisissez un créneau"
                                text="Le planning montre les terrains libres en temps réel, sans compte. Un créneau dure 90 minutes."
                            />
                            <Step
                                icon={CreditCard}
                                title="2. Payez en ligne"
                                text="Le créneau est bloqué 10 minutes pour vous. Facture immédiate, annulation remboursée jusqu’à 24 h avant."
                            />
                            <Step
                                icon={QrCode}
                                title="3. Entrez avec votre QR code"
                                text="Votre QR code personnel ouvre le terrain 15 minutes avant le début. Pas de clé, pas d’attente à l’accueil."
                            />
                        </ol>
                    </section>

                    <section id="tarifs" className="bg-white py-20">
                        <div className="mx-auto max-w-6xl px-4">
                            <h2 className="text-3xl font-extrabold uppercase">Tarifs</h2>
                            <p className="mt-2 text-[#5F6B78]">
                                Prix par terrain et par créneau de 90 minutes, TVA de 6 % comprise. À partager entre les joueurs.
                            </p>
                            <div className="mt-8 grid gap-4 md:grid-cols-2">
                                {[
                                    { title: 'Mode classique', rows: classic, highlight: false },
                                    {
                                        title: `Mode arcade (+${euros((arena[0]?.price_cents ?? 0) - (classic[0]?.price_cents ?? 0))})`,
                                        rows: arena,
                                        highlight: true,
                                    },
                                ].map(({ title, rows, highlight }) => (
                                    <div
                                        key={title}
                                        className={`rounded-xl border p-6 ${highlight ? 'border-[#C8E64A] bg-[#0E1B2C] text-[#F7F6F1]' : 'border-[#DAD7CC]'}`}
                                    >
                                        <h3 className="text-xl font-bold">{title}</h3>
                                        <ul className="mt-4 divide-y divide-current/10">
                                            {rows.map((p) => (
                                                <li key={p.name} className="flex justify-between py-3">
                                                    <span>
                                                        {p.name.replace('Arena ', '').replace(/^./, (c) => c.toUpperCase())} · {p.start} – {p.end}
                                                    </span>
                                                    <strong>{euros(p.price_cents)}</strong>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className={`${NAVY} relative overflow-hidden py-24 text-center`}>
                        <Photo name="joueurs" alt="" className="absolute inset-0 h-full w-full object-cover" />
                        <div className="absolute inset-0 bg-[#0E1B2C]/75" aria-hidden />
                        <div className="relative">
                            <h2 className="text-3xl font-extrabold uppercase md:text-4xl">Prêt à jouer ?</h2>
                            <p className="mt-3 text-[#F7F6F1]/80">Les créneaux du soir partent vite. Réservez en moins d’une minute.</p>
                            <Link href="/planning" className={`${CTA} mt-6`}>
                                Voir les créneaux libres
                            </Link>
                        </div>
                    </section>
                </main>

                <footer className="bg-[#0A1420] py-8 text-sm text-[#F7F6F1]/70">
                    <div className="mx-auto flex max-w-6xl flex-wrap justify-between gap-4 px-4">
                        <p>Arena Padel Club · Anderlecht, Bruxelles</p>
                        <nav aria-label="Liens du pied de page" className="flex gap-4">
                            <Link href="/planning" className="hover:text-[#C8E64A]">
                                Planning
                            </Link>
                            <Link href={auth.user ? '/bookings' : '/login'} className="hover:text-[#C8E64A]">
                                {auth.user ? 'Mes réservations' : 'Connexion'}
                            </Link>
                        </nav>
                    </div>
                </footer>
            </div>
        </>
    );
}
