import LegalLayout, { clubAddress, type Club } from '@/layouts/legal-layout';
import { useT } from '@/lib/i18n';

// Durées et données reprises du code : accounts:anonymize, activitylog:clean, cookies posés par Laravel et le menu.
export default function Privacy({ club }: { club: Club }) {
    const t = useT();
    const name = club.name ?? 'Arena Padel Club';
    const address = clubAddress(club);

    return (
        <LegalLayout
            title={t('Politique de confidentialité')}
            sections={[
                {
                    title: t('Qui traite vos données'),
                    lines: [
                        t(':name est responsable du traitement des données de ses joueurs. PadelBxl est son logiciel de réservation.', { name }),
                        address !== null && t('Adresse : :address.', { address }),
                        t('L’hébergeur et le service d’envoi des e-mails traitent les données pour le club, sous contrat.'),
                    ],
                },
                {
                    title: t('Les données gardées'),
                    lines: [
                        t(
                            'Votre compte : nom, e-mail, langue choisie et mot de passe, gardé sous forme d’empreinte, jamais en clair. Pour une entreprise, aussi son nom et son numéro de TVA.',
                        ),
                        t('Vos réservations, vos paiements, vos factures et vos notes de crédit.'),
                        t('L’adresse e-mail d’un partenaire que vous invitez.'),
                        t('Pour un client de l’accueil : son nom et un e-mail ou un numéro de téléphone.'),
                        t('Votre QR code : seule son empreinte est stockée.'),
                        t('Les passages à la borne : terrain, heure et résultat.'),
                        t('Les actions sensibles du personnel, dans un journal.'),
                    ],
                },
                {
                    title: t('Pourquoi'),
                    lines: [
                        t('Le compte, les réservations et le QR code servent à exécuter le contrat de réservation.'),
                        t('Les factures et les notes de crédit sont une obligation légale.'),
                        t(
                            'Le journal de la borne et celui du personnel servent la sécurité et le traitement des litiges. C’est l’intérêt légitime du club.',
                        ),
                    ],
                },
                {
                    title: t('Combien de temps'),
                    lines: [
                        t('Le compte reste tant qu’il est actif.'),
                        t('Vous pouvez désactiver votre compte dans vos paramètres. Vos réservations à venir doivent d’abord être annulées.'),
                        t(
                            'Un compte désactivé est caché. Trente jours plus tard, le nom, l’e-mail et les données d’entreprise sont remplacés. Le mot de passe, la double authentification et les jetons d’accès sont effacés.',
                        ),
                        t(
                            'Les factures et les notes de crédit gardent le nom du client. Elles sont conservées 7 ans, comme le demande le Code de la TVA.',
                        ),
                        t('Les passages à la borne sont effacés après trois mois.'),
                        t('Le journal des actions du personnel est effacé après un an.'),
                    ],
                },
                {
                    title: t('Qui voit vos données'),
                    lines: [
                        t('Le personnel du club, pour gérer les réservations.'),
                        t('L’organisateur revoit l’adresse e-mail qu’il a tapée pour un invité, jamais le nom de son compte.'),
                        t('Un membre qui prend une place ouverte ne voit que le prénom de l’organisateur.'),
                        t('La borne n’affiche que le prénom du joueur qui entre.'),
                        t('Les données ouvertes du club ne montrent que les créneaux libres, jamais qui joue.'),
                    ],
                },
                {
                    title: t('La sécurité'),
                    lines: [
                        t('Un mot de passe compte au moins 15 caractères. Un mot de passe déjà paru dans une fuite connue est refusé.'),
                        t('Le personnel du club se connecte avec une double authentification.'),
                        t('Le nombre d’essais est limité sur les formulaires sensibles.'),
                    ],
                },
                {
                    title: t('Vos droits'),
                    lines: [
                        t('Vous voyez et corrigez votre nom et votre e-mail dans vos paramètres.'),
                        t('Vous téléchargez une copie de vos données, dans un fichier JSON, depuis vos paramètres.'),
                        t('Vous pouvez désactiver votre compte. Il est anonymisé trente jours plus tard.'),
                        t('Pour toute autre demande sur vos données, adressez-vous à l’accueil du club.'),
                        t('Vous pouvez porter plainte auprès de l’Autorité de protection des données.'),
                    ],
                },
                {
                    title: t('Les cookies'),
                    lines: [
                        t(
                            'PadelBxl n’utilise que des cookies nécessaires. Il n’y a ni mesure d’audience ni publicité, donc aucun bandeau à accepter.',
                        ),
                        t('Le cookie de session garde votre connexion. Il expire après deux heures sans activité.'),
                        t('Le cookie XSRF-TOKEN protège vos formulaires contre une requête envoyée à votre insu.'),
                        t('Le cookie « Rester connecté » n’existe que si vous cochez cette case à la connexion.'),
                        t('Le cookie sidebar:state retient si le menu latéral est ouvert, pendant 7 jours.'),
                        t('La langue choisie est gardée dans la session et dans votre compte.'),
                    ],
                },
            ]}
        />
    );
}
