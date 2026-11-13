import LanguageSwitcher from '@/components/language-switcher';
import { useT } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

/** Coordonnées du club, reprises du .env (CLUB_*). Une valeur absente vaut null. */
export type Club = {
    name: string | null;
    vat: string | null;
    street: string | null;
    postcode: string | null;
    city: string | null;
    email?: string | null;
};

export type Section = { title: string; lines: (string | false)[] };

// À changer à chaque modification d'un des deux textes : c'est la version que le joueur accepte au paiement.
const VERSION = '11/11/2026';

// Pages publiques : lisibles sans compte, depuis la case « conditions » du paiement ou le pied de page.
export default function LegalLayout({ title, sections }: { title: string; sections: Section[] }) {
    const t = useT();

    return (
        <>
            <Head title={title} />
            <div className="min-h-screen bg-[#F7F6F1] text-[#0E1B2C]">
                <header className="bg-[#0E1B2C] text-[#F7F6F1]">
                    <nav aria-label={t('Navigation principale')} className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4">
                        <Link href="/" aria-label={t('PadelBxl, accueil')}>
                            <img src="/images/logo-fond-sombre.webp" alt="PadelBxl" className="h-10 w-auto" />
                        </Link>
                        <LanguageSwitcher />
                    </nav>
                </header>
                <main id="contenu" className="mx-auto max-w-3xl px-4 py-12">
                    <h1 className="text-3xl font-extrabold uppercase">{title}</h1>
                    <p className="mt-2 text-sm text-[#5F6B78]">{t('Version du :date', { date: VERSION })}</p>
                    {sections.map((s) => (
                        <section key={s.title} className="mt-8">
                            <h2 className="text-xl font-bold">{s.title}</h2>
                            <ul className="mt-3 list-disc space-y-2 pl-5">
                                {s.lines.filter(Boolean).map((line) => (
                                    <li key={line as string}>{line}</li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </main>
                <footer className="bg-[#0A1420] py-8 text-sm text-[#F7F6F1]/70">
                    <nav aria-label={t('Liens du pied de page')} className="mx-auto flex max-w-3xl flex-wrap gap-4 px-4">
                        <Link href="/" className="hover:text-[#C8E64A]">
                            {t('Accueil')}
                        </Link>
                        <Link href="/conditions-generales" className="hover:text-[#C8E64A]">
                            {t('Conditions générales')}
                        </Link>
                        <Link href="/confidentialite" className="hover:text-[#C8E64A]">
                            {t('Confidentialité')}
                        </Link>
                    </nav>
                </footer>
            </div>
        </>
    );
}

/** « Rue du Padel 1, 1070 Anderlecht », ou null si le club n'a pas renseigné son adresse. */
export function clubAddress(club: Club): string | null {
    return club.street && club.postcode && club.city ? `${club.street}, ${club.postcode} ${club.city}` : null;
}
