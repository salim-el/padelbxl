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

## Plan jusqu'au 2 décembre 2026

Rythme prévu : 10 h du lundi au jeudi, 12 h du vendredi au dimanche. Les fonctions notées 4 ou 5 dans le T03 passent d'abord. Chaque lot a sa branche, des commits par couche, ses tests, puis une fusion dans `develop`. Le temps réel de chaque lot est relevé pour vérifier l'estimation.

| Jour | Branche | Objectif |
|---|---|---|
| Dim. 15/11 | `feature/account-suspension` | F28 et F29 : motif communiqué au joueur, durée avec levée automatique, révocation, signalements, contestation examinée par un autre membre du personnel |
| Lun. 16/11 | `feature/counter-and-guest-access` | F25 et F18 : QR code envoyé au client du comptoir, lien à usage unique pour un invité sans compte, lecture du QR à la caméra sur la borne |
| Mar. 17/11 | `feature/profile-and-pricing` | F21 : téléphone, langue et niveau dans le profil. F24 (début) : créer et modifier les tarifs, TVA, heures creuses |
| Mer. 18/11 | `feature/club-settings-and-staff` | F24 (fin) : horaires d'ouverture. F35 (début) : comptes du personnel et rôles |
| Jeu. 19/11 | `feature/stripe-payment` | F9 : paiement réel en mode test Stripe, carte et Bancontact, authentification forte (dépend des clés de test) |
| Ven. 20/11 | `feature/stripe-webhooks` | Confirmation par notification signée ; F30 : contestation bancaire, réservation gelée, compte signalé |
| Sam. 21/11 | `chore/deployment`, `feature/accessibility-audit` | Mise en ligne pour le jury (dépend de l'hébergement) ; contrôle WCAG 2.1 AA |
| Dim. 22/11 | `feature/store-credits`, `feature/booking-change` | F15 : avoirs déduits au paiement. F14 : déplacer une réservation |
| Lun. 23/11 | `feature/reminders` | F16 : rappel 24 h avant ; F21 : préférences de notification ; F13 : créneau libéré proposé à la liste d'attente |
| Mar. 24/11 | `feature/emergency-codes`, `feature/no-shows` | F19 : code de secours de la borne. F31 : absences comptées et signalées |
| Mer. 25/11 | `feature/accounting-export`, `feature/dashboard-detail` | F33 : export mensuel pour le comptable. F34 : occupation par terrain et par heure, annulations |
| Jeu. 26/11 | `feature/level-matching`, `feature/staff-i18n` | F11 : appel envoyé aux joueurs d'un niveau proche. F23 : écrans du personnel en trois langues |
| Ven. 27/11 | `feature/localized-urls`, `feature/admin-content` | Adresses par langue, hreflang et plan du site (T17). F35 (fin) : textes, traductions, documents légaux, clés d'API |
| Sam. 28/11 | — | Documents : T03, T06, T08, T09 |
| Dim. 29/11 | — | Documents : T12, T16, T17, T18, T20, questions de la défense |
| Lun. 30/11 | `test/end-to-end` | Tests de bout en bout des parcours principaux, corrections |
| Mar. 01/12 | — | Marge pour les imprévus |
| Mer. 02/12 | `release/1.0.0` | Version livrée au jury, étiquette `v1.0.0` |

## Idées à étudier après la bêta

| Idée | Intérêt | Difficulté |
|---|---|---|
| Réservation récurrente | Le même créneau chaque semaine pour un groupe fixe | Moyenne |
| Événement privé | Plusieurs terrains en même temps pour une entreprise ou un anniversaire | Moyenne |

Écartée : une musique choisie par les joueurs. Diffuser de la musique dans un lieu public demande une licence payante (Unisono en Belgique).
