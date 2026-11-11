import LegalLayout, { clubAddress, type Club } from '@/layouts/legal-layout';
import { useT } from '@/lib/i18n';

// Chaque règle reprend ce que fait le code (BookingService) : si l'un change, l'autre suit.
export default function Terms({ club }: { club: Club }) {
    const t = useT();
    const name = club.name ?? 'Arena Padel Club';
    const address = clubAddress(club);

    return (
        <LegalLayout
            title={t('Conditions générales')}
            sections={[
                {
                    title: t('Le club'),
                    lines: [
                        t(':name exploite six terrains de padel couverts à Anderlecht.', { name }),
                        address !== null && t('Adresse : :address.', { address }),
                        club.vat !== null && t('Numéro de TVA : :vat.', { vat: club.vat }),
                        t('Ces conditions valent pour toute réservation faite sur PadelBxl ou à l’accueil du club.'),
                    ],
                },
                {
                    title: t('Le compte'),
                    lines: [
                        t('Pour réserver, il faut un compte actif et une adresse e-mail confirmée.'),
                        t('Un joueur a au plus trois réservations à venir.'),
                        t(
                            'Le club peut suspendre un compte. Un compte suspendu ne peut plus réserver ni payer. Cette décision est toujours prise par une personne du club.',
                        ),
                    ],
                },
                {
                    title: t('Les créneaux et les prix'),
                    lines: [
                        t('Un créneau dure 90 minutes. Le premier commence à 9 h, le dernier à 21 h.'),
                        t('On réserve jusqu’à 30 jours à l’avance.'),
                        t('Les prix sont affichés TVA comprise. La TVA est de 6 % sur l’accès au terrain et de 21 % sur le matériel loué ou vendu.'),
                        t('Une réservation garde le prix affiché au moment où elle est faite, même si le tarif change ensuite.'),
                    ],
                },
                {
                    title: t('La réservation et le paiement'),
                    lines: [
                        t(
                            'Le créneau choisi est bloqué 10 minutes pendant le paiement. Sans paiement dans ce délai, il est libéré et rien n’est débité.',
                        ),
                        t('Le paiement se fait par carte en ligne, ou en espèces et par terminal à l’accueil.'),
                        t('Version alpha : le paiement est simulé. Aucune carte n’est débitée.'),
                        t(
                            'Chaque paiement donne une facture au nom de celui qui paie. Une entreprise reçoit aussi sa facture au format électronique UBL.',
                        ),
                    ],
                },
                {
                    title: t('Le partage du prix'),
                    lines: [
                        t(
                            'Pour un match dans plus de 24 h, l’organisateur peut partager le prix avec trois autres joueurs au plus. Il les invite par e-mail ou ouvre des places aux membres du club.',
                        ),
                        t('Le prix est divisé en parts égales. Les centimes qui restent sont à la charge de l’organisateur.'),
                        t('Chaque joueur paie sa part et reçoit sa propre facture et son propre QR code.'),
                        t('L’organisateur se porte garant : 24 h avant le match, il paie les parts encore impayées.'),
                        t('Un invité peut refuser jusqu’à 24 h avant le match. Sa place est alors proposée aux membres du club.'),
                        t(
                            'Une place ouverte revient au premier membre qui paie sa part. Quand la partie est complète, un membre peut s’inscrire sur la liste d’attente. Si une place se libère, chaque inscrit reçoit un e-mail et le premier qui paie prend la place.',
                        ),
                    ],
                },
                {
                    title: t('L’annulation'),
                    lines: [
                        t('L’organisateur annule depuis son espace.'),
                        t(
                            'Jusqu’à 24 h avant le début, chaque paiement de la partie est remboursé en entier. Une note de crédit renvoie à la facture d’origine.',
                        ),
                        t('Moins de 24 h avant le début, rien n’est remboursé. Les parts encore impayées sont payées par l’organisateur.'),
                        t('Une fois le créneau commencé, la réservation ne s’annule plus.'),
                        t('À l’annulation, les QR codes de la partie sont désactivés.'),
                        t('Une réservation faite à l’accueil suit les mêmes règles.'),
                    ],
                },
                {
                    title: t('Pas de droit de rétractation'),
                    lines: [
                        t(
                            'Une réservation de terrain est un service de loisir à une date précise. Le droit de rétractation de 14 jours ne s’applique donc pas (Code de droit économique, article VI.53, 12°).',
                        ),
                        t('Le remboursement jusqu’à 24 h avant le match est un geste du club, pas une obligation.'),
                    ],
                },
                {
                    title: t('L’accès au terrain'),
                    lines: [
                        t('Chaque joueur de la partie a son propre QR code.'),
                        t('Il ouvre ce terrain seulement, de 15 minutes avant le début jusqu’à la fin du créneau. Il ne sert qu’une fois.'),
                        t('Chaque passage à la borne est enregistré, qu’il soit accepté ou refusé.'),
                        t(
                            'Le club ne peut pas fermer un terrain sur un créneau réservé : la fermeture est refusée tant que la réservation est active.',
                        ),
                    ],
                },
                {
                    title: t('Questions et litiges'),
                    lines: [
                        t('Une question ou une réclamation se pose à l’accueil du club.'),
                        t('En cas de litige, le joueur peut aussi s’adresser au Service de médiation pour le consommateur.'),
                    ],
                },
            ]}
        />
    );
}
