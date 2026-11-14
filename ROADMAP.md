# Feuille de route

## Conventions

**Branches** (Git Flow)

| Branche | Rôle |
|---|---|
| `main` | Versions livrées uniquement, chacune marquée d'une étiquette (`v0.1.0-alpha`, `v0.2.0-beta`…) |
| `develop` | Intégration des fonctionnalités terminées |
| `feature/<sujet>` | Une fonctionnalité, créée depuis `develop`, fusionnée dans `develop` |
| `bugfix/<sujet>` | Correction d'une anomalie trouvée sur `develop` |
| `release/<version>` | Préparation d'une version, fusionnée dans `main` et dans `develop` |
| `hotfix/<sujet>` | Correction urgente d'une version livrée, créée depuis `main` |

Noms en anglais, en minuscules, mots séparés par des tirets.

**Messages de commit** (Conventional Commits)

`type(portée): description courte en français`, avec `feat`, `fix`, `style`, `docs`, `test`, `refactor` ou `chore`.

## Version alpha — v0.1.0-alpha (livrée)

| Branche | Contenu |
|---|---|
| `feature/database-schema` | Tables du domaine, modèles, données de démonstration |
| `feature/planning` | Planning public, prix, navigation par jour |
| `feature/booking` | Réservation, paiement simulé, facture, annulation |
| `feature/access-kiosk` | QR code personnel, borne d'accès |
| `feature/admin-dashboard` | Tableau de bord du gérant, gestion des comptes |
| `feature/ui-theme` | Charte graphique du T10, composants visuels |
| `feature/i18n-fr` | Interface et e-mails en français |
| `feature/password-policy` | Mot de passe de 15 caractères minimum |
| `bugfix/confirm-password-throttle` | Limite d'essais sur la confirmation du mot de passe |

## Version alpha — v0.1.0-alpha (octobre 2026)

| Branche | Contenu |
|---|---|
| `feature/home-page`, `feature/home-images` | Page d'accueil publique, photos |
| `feature/app-visual-refresh`, `bugfix/planning-cell-size` | Nouveau visuel des écrans connectés |
| `feature/arena-game-mode`, `feature/auth-layout-and-arcade` | Mode arcade sur les six terrains (+14 €), choix au paiement ; écrans de connexion revus |
| `feature/arcade-zones` | Un seul mode Arcade, aligné sur le document de conception : zones Or (point double), Rouge (faute) et Bleue (points Arena) |
| `feature/flash-slots`, `feature/rematch` | Créneaux flash (-20 % à moins de 3 h) ; revanche la semaine suivante |
| `feature/court-management` | Tarifs et fermetures de terrains gérés par le gérant |
| `feature/equipment-rental` | Location et vente de matériel ; facture à plusieurs lignes (6 % terrain, 21 % matériel) ; stock par créneau |
| `feature/company-accounts` | Inscription en particulier ou en entreprise ; numéro de TVA belge contrôlé et repris sur la facture |
| `chore/ci-github-actions` | Intégration continue : Pint, Prettier, ESLint et PHPUnit sur PostgreSQL 16 |

## Version bêta — v0.2.0-beta (livraison au plus tard le 30 novembre 2026)

Déjà fusionné dans `develop` :

| Branche | Contenu |
|---|---|
| `fix/account-soft-delete` | Désinscription en soft delete, anonymisation après 30 jours |
| `feature/i18n-nl-en`, `feature/i18n-pages` | Interface joueur en français, néerlandais et anglais |
| `feature/api-rest`, `feature/opendata-jsonld` | API REST `/api/v1` (Sanctum), créneaux libres en JSON-LD ouvert |
| `feature/security-headers` | Politique CSP avec nonce, HSTS, protection contre l'affichage dans un cadre |
| `feature/activity-log`, `feature/activity-log-screen` | Journal des actions sensibles, gardé un an, consultable par le gérant |
| `feature/staff-two-factor` | Double authentification obligatoire du personnel, refus des mots de passe déjà fuités |
| `feature/counter-booking` | Réservation au comptoir pour un client sans compte, ticket et QR code imprimés |
| `feature/ubl-export` | Facture électronique UBL (Peppol BIS 3.0) pour les clients entreprises |
| `feature/split-payment` | Partage du prix entre quatre joueurs au plus, invitation par e-mail, part payée par chacun avec sa facture et son QR code ; carte de garantie de l'organisateur débitée des parts impayées 24 h avant le match |
| `feature/open-matches` | Places ouvertes aux membres du club au moment du paiement, place prise en payant sa part, liste d'attente prévenue par e-mail quand un invité refuse ; liste sans nom ni adresse des joueurs |
| `refactor/remove-arcade` | Retrait du mode Arcade : terrains classiques seulement, tarifs et supplément Arena supprimés, accueil, planning, paiement, comptoir et API simplifiés ; les réservations déjà payées gardent leur prix et leurs factures |
| `refactor/terminal-wording`, `docs/readme-degree` | L'appareil du terrain devient un écran ; intitulé officiel du bachelier |
| `fix/email-verification`, `fix/email-change-password` | E-mail de confirmation à l'inscription ; mot de passe demandé pour changer d'adresse |
| `test/untested-rules` | Tests du créneau commencé, de l'annulation d'une partie commencée et de la limite de l'API |
| `fix/booking-concurrency` | Verrous sur l'expiration d'un blocage, la fermeture d'un terrain et le plafond de trois réservations |
| `fix/account-safety`, `fix/deletion-paid-share` | Désinscription refusée à un invité qui a payé sa part ; jetons révoqués au changement de mot de passe ; pas de jeton d'API pour le personnel |
| `fix/history-and-stock` | Passage à la borne dans l'historique ; stock des articles vendus |
| `refactor/staff-policy` | Droits du personnel définis à un seul endroit (Gate `manage-club`) |
| `feature/legal-pages` | Pages publiques des conditions générales et de la confidentialité |
| `chore/demo-data` | Données de démonstration réalistes (`DemoSeeder`) |
| `feature/invoice-pdf` | Factures et notes de crédit en PDF |
| `feature/access-log-retention`, `feature/data-export`, `feature/cookie-notice`, `chore/club-email` | Purge du journal de la borne après trois mois ; export des données personnelles ; bandeau d'information sur les cookies ; e-mail de contact du club |

Fusionné le 14 novembre 2026 :

| Branche | Contenu |
|---|---|
| `bugfix/align-docs-code` | Jetons d'API révoqués après « mot de passe oublié » ; adresse d'invitation effacée à l'anonymisation ; balise noindex sur les pages privées |
| `feature/account-reactivation` | F4 : réactivation du compte par le joueur pendant trente jours, lien signé à usage unique |
| `feature/legal-consents` | F1 et F7 : date de naissance, niveau, parent sous 13 ans, adresses jetables refusées, versions acceptées enregistrées, nouvelle version à accepter avant de réserver |
| `feature/close-sessions-on-password-change` | F3 : un nouveau mot de passe ferme les autres sessions |
| `feature/exceptional-requests` | F17 et F27 : demande pour un imprévu grave, pièce privée, décision motivée, remboursement total ou partiel |

## Plan jusqu'à la remise du 26 novembre 2026

Le rapport écrit final (S1-B) est remis le **jeudi 26 novembre 2026**. Le dossier rassemble le rapport en PDF, l'archive ZIP du code avec le dump, le support de présentation et l'attestation anti-plagiat. La défense a lieu mi-décembre.

Rythme : 7 h par jour, environ 10 commits par lot, et seulement le lot du jour. Chaque lot a sa branche, des commits par couche et ses tests, puis il est fusionné dans `develop`. Le temps réel de chaque lot est noté dans `VERIFICATION/estimation_temps_codage.md`.

Déjà fusionnés : F4, F1/F7, F3, F17/F27, F28/F29, F25/F18, F21/F24, F24 horaires, F35 comptes du personnel et rôles, F11 appel aux joueurs d'un niveau proche (14/11, en avance d'un jour).

### Code (15/11 au 22/11)

| Jour | Branche | Objectif |
|---|---|---|
| Dim. 15/11 | `bugfix/refund-gaps` | Trous de l'audit du 14/11 : remboursement partiel puis annulation, demande acceptée avant le match, annulation par le club, réservations d'un compte suspendu, invités qui ont payé, notes de crédit multiples, jetons d'API à la désactivation |
| Lun. 16/11 | `feature/staff-i18n`, `chore/docker` | F23 : écrans du personnel en trois langues. Fichier Docker |
| Mar. 17/11 | `feature/store-credits` | F15 : avoirs déduits au paiement |
| Mer. 18/11 | `feature/booking-change`, `feature/waitlist` | F14 : déplacer une réservation. F13 : créneau libéré proposé à la liste d'attente |
| Jeu. 19/11 | `feature/stripe-payment` | F9 : paiement réel en mode test Stripe (clés de test nécessaires le 18/11) |
| Ven. 20/11 | `feature/stripe-webhooks` | Confirmation par notification signée ; F30 : contestation bancaire, réservation gelée |
| Sam. 21/11 | `chore/deployment` | Mise en ligne sur Render, e-mails par Brevo (comptes nécessaires le 20/11) |
| Dim. 22/11 | `bugfix/*` | Corrections après la mise en ligne, parcours principaux testés sur l'adresse publique |

Ces jours sont serrés. Si un lot déborde, F13 puis F30 passent après la remise, et le rapport l'indique.

### Rapport (23/11 au 26/11)

| Jour | Travail |
|---|---|
| Lun. 23/11 | Gel du code, étiquette `v1.0.0-rc.1`. T03, T06, T07, T08, T09, T14 et T18 réalignés sur le code par les générateurs. Les chiffres (tests, commits, tables) sont relevés le jour même |
| Mar. 24/11 | Générateur du rapport final, qui rassemble T01 à T20 comme le S1-B de référence. Nouvelles parties : enquête, difficultés rencontrées, réalisation (alpha, bêta, ce qui reste à faire) |
| Mer. 25/11 | Relecture complète, PDF, archive ZIP du code et du dump, support de présentation |
| Jeu. 26/11 | Remise du dossier |

### Après la remise (27/11 jusqu'à la défense)

F16 rappels et F21 préférences, F19 codes de secours, F31 absences, F33 export comptable, F34 détail du tableau de bord, F35 (fin) contenus, référencement (T17), contrôle WCAG 2.1 AA, tests de bout en bout. Le rapport du 26/11 les présente comme « v1.1, après la remise », avec la raison. L'erratum remis au jury et la version `v1.0.0` arrivent avant la défense.

### À faire par Salim

| Pour le | Tâche |
|---|---|
| 18/11 | Clés de test Stripe dans `.env` |
| 20/11 | Comptes Render et Brevo |
| 22/11 | Enquête auprès des joueurs (réelle, au moins 20 réponses), résultats bruts transmis pour le rapport |
| 22/11 | Publication GitHub relancée : le dépôt doit montrer un travail régulier |
| 25/11 | Page de garde conforme au modèle officiel ; attestation anti-plagiat remplie et signée |

## Idées à étudier après la bêta

| Idée | Intérêt | Difficulté |
|---|---|---|
| Réservation récurrente | Le même créneau chaque semaine pour un groupe fixe | Moyenne |
| Événement privé | Plusieurs terrains en même temps pour une entreprise ou un anniversaire | Moyenne |

Écartée : une musique choisie par les joueurs. Diffuser de la musique dans un lieu public demande une licence payante (Unisono en Belgique).
