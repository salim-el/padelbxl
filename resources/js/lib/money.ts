export const euros = (cents: number | null | undefined) =>
    cents == null ? '–' : (cents / 100).toLocaleString('fr-BE', { style: 'currency', currency: 'EUR' });

export const STATUS_LABELS: Record<string, string> = {
    pending_payment: 'En attente de paiement',
    expired: 'Expirée',
    confirmed: 'Confirmée',
    used: 'Utilisée',
    missed: 'Manquée',
    cancelled_refunded: 'Annulée et remboursée',
    cancelled_unrefunded: 'Annulée sans remboursement',
    frozen: 'Gelée',
};

// État de la part d'un joueur quand le prix est partagé.
export const SHARE_LABELS: Record<string, string> = {
    invited: 'Part à payer',
    paid: 'Part réglée',
    covered: 'Réglée par l’organisateur',
};

// Majuscule au premier mot seulement, comme le veut le français (« Dimanche 25 octobre »).
export const ucfirst = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

const MONTHS: Record<string, string> = {
    janvier: 'janv.',
    février: 'févr.',
    mars: 'mars',
    avril: 'avr.',
    mai: 'mai',
    juin: 'juin',
    juillet: 'juil.',
    août: 'août',
    septembre: 'sept.',
    octobre: 'oct.',
    novembre: 'nov.',
    décembre: 'déc.',
};
// En néerlandais et en anglais, les trois premières lettres du mois suffisent (okt, Oct).
export const shortMonth = (m: string | undefined) => (m ? (MONTHS[m] ?? m.slice(0, 3)) : '');

export const GAME_MODES: Record<string, { label: string; text: string }> = {
    classic: { label: 'Classique', text: 'Un match de padel normal, sans supplément.' },
    arcade: {
        label: 'Arcade',
        text: 'Les règles du padel, avec des zones lumineuses en miroir dans les deux camps : Or (point double), Rouge (faute) et Bleue (points Arena).',
    },
};

// Supplément du mode arcade, identique à l'écart des tarifs (48 € → 62 €).
export const ARCADE_SUPPLEMENT_CENTS = 1400;
