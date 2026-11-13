import { useT } from '@/lib/i18n';

// Lien sous chaque case « conditions » : nouvel onglet, pour ne pas perdre le créneau bloqué pendant la lecture.
export default function TermsLink() {
    const t = useT();

    return (
        <p className="text-sm text-[#5F6B78]">
            <a href="/conditions-generales" target="_blank" rel="noopener" className="font-semibold text-[#1D5C7A] underline">
                {t('Lire les conditions générales')}
            </a>
            {' · '}
            <a href="/confidentialite" target="_blank" rel="noopener" className="font-semibold text-[#1D5C7A] underline">
                {t('Politique de confidentialité')}
            </a>
        </p>
    );
}
