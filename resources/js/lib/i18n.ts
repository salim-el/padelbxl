import en from '@/i18n/en';
import nl from '@/i18n/nl';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

const DICTIONNAIRES: Record<string, Record<string, string>> = { en, nl };

// Les textes sont écrits en français dans le code ; le dictionnaire de la langue choisie fournit la traduction.
export function useT() {
    const { locale } = usePage<SharedData>().props;
    const dictionnaire = DICTIONNAIRES[locale] ?? {};

    // Les variables s'écrivent :nom dans le texte, par exemple t('Payer :amount', { amount: '48,00 €' }).
    return (texte: string, variables: Record<string, string | number> = {}) =>
        Object.entries(variables).reduce((s, [nom, valeur]) => s.replaceAll(`:${nom}`, String(valeur)), dictionnaire[texte] ?? texte);
}
