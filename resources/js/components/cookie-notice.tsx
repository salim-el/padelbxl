import { useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const KEY = 'padelbxl.cookie-notice';
// Environ six mois, la durée que l'APD retient pour le choix d'un visiteur : ensuite le bandeau revient.
const SIX_MONTHS = 1000 * 60 * 60 * 24 * 182;

// Le stockage peut être refusé (navigation privée, données bloquées) : le bandeau s'affiche alors, sans erreur.
function closedRecently(): boolean {
    try {
        const at = Number(window.localStorage.getItem(KEY));
        return at > 0 && Date.now() - at < SIX_MONTHS;
    } catch {
        return false;
    }
}

/**
 * Bandeau d'information : l'application ne pose que des cookies nécessaires, il n'y a donc aucun consentement à
 * recueillir (APD). Si un cookie de mesure d'audience ou de publicité est ajouté, il faudra « Accepter / Refuser » ici.
 */
export default function CookieNotice() {
    const t = useT();
    const [open, setOpen] = useState(false);
    // Lu après le montage : le rendu serveur et le premier rendu du navigateur restent identiques.
    useEffect(() => setOpen(!closedRecently()), []);

    if (!open) {
        return null;
    }
    const close = () => {
        try {
            window.localStorage.setItem(KEY, String(Date.now()));
        } catch {
            // Stockage indisponible : le bandeau se ferme pour cette visite seulement.
        }
        setOpen(false);
    };

    return (
        <section
            aria-label={t('Cookies')}
            className="fixed inset-x-0 bottom-0 z-50 border-t border-[#DAD7CC] bg-white p-4 text-sm text-[#0E1B2C] shadow-lg print:hidden"
        >
            <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-3">
                <p className="min-w-60 flex-1">
                    {t(
                        'PadelBxl n’utilise que des cookies nécessaires : connexion, sécurité des formulaires et menu. Ni mesure d’audience ni publicité, il n’y a donc rien à accepter.',
                    )}{' '}
                    <Link href="/confidentialite" className="font-semibold text-[#1D5C7A] underline">
                        {t('En savoir plus')}
                    </Link>
                </p>
                <button
                    type="button"
                    onClick={close}
                    className="rounded-lg bg-[#0E1B2C] px-4 py-2 font-semibold text-[#F7F6F1] focus-visible:ring-2 focus-visible:ring-[#C8E64A] focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    {t('J’ai compris')}
                </button>
            </div>
        </section>
    );
}
