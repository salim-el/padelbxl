# Feuille de route

## Conventions

**Branches** (Git Flow)

| Branche | Rôle |
|---|---|
| `main` | Versions livrées uniquement, chacune marquée d'une étiquette (`v0.1.0-alpha`, `v1.0.0`) |
| `develop` | Intégration des fonctionnalités terminées |
| `feature/<sujet>` | Une fonctionnalité, créée depuis `develop`, fusionnée dans `develop` |
| `fix/<sujet>`, `bugfix/<sujet>` | Correction d'une anomalie trouvée sur `develop` |
| `test/<sujet>` | Tests ajoutés à une règle déjà codée |
| `refactor/<sujet>`, `style/<sujet>`, `chore/<sujet>`, `docs/<sujet>` | Réorganisation du code, mise en forme, outillage et documentation |
| `release/<version>` | Préparation d'une version, fusionnée dans `main` et dans `develop` |

Noms en anglais, en minuscules, mots séparés par des tirets.

**Messages de commit** (Conventional Commits)

`type(portée): description courte en français`, avec `feat`, `fix`, `style`, `docs`, `test`, `refactor`, `chore` ou `ci`.

## Version alpha — v0.1.0-alpha (29 octobre 2026)

La version alpha couvre le chemin principal : trouver un créneau, réserver, payer, entrer.
Elle compte 78 tests, dont 5 pour le mode Arcade, retiré le 9 novembre 2026.

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
| `feature/home-page`, `feature/home-images` | Page d'accueil publique, photos |
| `feature/app-visual-refresh`, `bugfix/planning-cell-size` | Nouveau visuel des écrans connectés |
| `feature/arena-game-mode`, `feature/auth-layout-and-arcade`, `feature/arcade-zones` | Mode Arcade sur les six terrains (+14 €). Retiré le 9 novembre 2026 |
| `feature/flash-slots`, `feature/rematch` | Créneaux flash (-20 % à moins de 3 h) ; revanche la semaine suivante |
| `feature/court-management` | Tarifs et fermetures de terrains gérés par le gérant |
| `feature/equipment-rental` | Location et vente de matériel ; facture à plusieurs lignes (6 % terrain, 21 % matériel) ; stock par créneau |
| `feature/company-accounts` | Inscription en particulier ou en entreprise ; numéro de TVA belge contrôlé et repris sur la facture |
| `chore/ci-github-actions` | Intégration continue : Pint, Prettier, ESLint et PHPUnit sur PostgreSQL 16 |

## Version remise — v1.0.0 (26 novembre 2026)

Un premier gel date du 15 novembre 2026, au commit `e1e5aef`, avec 250 tests et 1536 vérifications.
Le 16 novembre 2026, ce fichier et le README ont été mis à jour, sans changer le code.
Le 17 novembre 2026, un réglage des tests (`config/inertia.php`) a corrigé l'intégration continue sous Linux. Il ne change pas l'application.
Le plan de code a été repris le 17 novembre 2026. Le paiement par Stripe, les avoirs, le déplacement d'une réservation et les fichiers de mise en ligne passent avant la remise.
Le code du projet est gelé le 25 novembre 2026, au commit `0fa88db`. La suite compte 294 tests et 1796 vérifications.
Le 26 novembre 2026, Symfony passe à la version 8.1.12. L'audit des dépendances signalait cinq failles publiées la veille.
Le même jour, ce fichier et le README sont mis à jour et l'étiquette `v1.0.0` est posée sur `main`.

| Fusion | Branche | Contenu |
|---|---|---|
| 30/10 | `chore/rename-padelbxl`, `bugfix/symfony-mime-cve` | Projet renommé ArenaBxl ; PHP 8.4.27 au moins et correctif de symfony/mime |
| 30/10 | `fix/account-soft-delete` | Désinscription en soft delete, anonymisation après 30 jours |
| 31/10 au 02/11 | `feature/i18n-nl-en`, `feature/i18n-pages` | Interface du joueur en français, néerlandais et anglais |
| 01/11 au 02/11 | `feature/api-rest`, `feature/opendata-jsonld` | API REST `/api/v1` (Sanctum), créneaux libres en JSON-LD ouvert |
| 03/11 | `feature/security-headers` | Politique CSP avec nonce, HSTS en HTTPS, protection contre l'affichage dans un cadre |
| 03/11 | `feature/activity-log`, `feature/activity-log-screen` | Journal des actions sensibles, gardé un an, consultable par le gérant |
| 03/11 | `feature/staff-two-factor` | Double authentification obligatoire du personnel, refus des mots de passe déjà fuités |
| 03/11 | `feature/counter-booking` | Réservation au comptoir pour un client sans compte, ticket et QR code imprimés |
| 05/11 | `feature/ubl-export` | Facture électronique UBL (Peppol BIS 3.0) pour les clients entreprises |
| 08/11 | `feature/split-payment` | Partage du prix entre les joueurs invités, quatre au plus, invitation par e-mail ; parts impayées débitées à l'organisateur 24 h avant le match (paiement simulé) |
| 09/11 | `feature/open-matches` | Places ouvertes aux membres du club, liste d'attente prévenue quand une place se libère dans une partie |
| 09/11 | `refactor/remove-arcade` | Retrait du mode Arcade : terrains classiques seulement ; les réservations déjà payées gardent leur prix et leurs factures |
| 10/11 | `refactor/terminal-wording` | L'appareil du terrain devient un écran |
| 11/11 | `fix/email-verification`, `fix/email-change-password` | E-mail de confirmation à l'inscription ; mot de passe demandé pour changer d'adresse |
| 11/11 | `test/untested-rules` | Tests du créneau commencé, de l'annulation d'une partie commencée et de la limite de l'API |
| 11/11 | `fix/booking-concurrency` | Verrous sur l'expiration d'un blocage, la fermeture d'un terrain et le plafond de trois réservations |
| 11/11 | `fix/account-safety` | Désinscription refusée à un invité qui a payé sa part ; jetons révoqués au changement de mot de passe |
| 11/11 | `fix/history-and-stock` | Passage à la borne dans l'historique ; stock des articles vendus |
| 11/11 | `refactor/staff-policy` | Droits du personnel définis à un seul endroit (Gate `manage-club`) |
| 11/11 | `feature/legal-pages`, `feature/cookie-notice` | Conditions générales et confidentialité ; bandeau d'information sur les cookies |
| 11/11 | `feature/invoice-pdf` | Factures et notes de crédit en PDF |
| 11/11 | `feature/access-log-retention`, `feature/data-export` | Journal de la borne purgé après trois mois ; export des données personnelles |
| 11/11 | `feature/contact-form`, `chore/club-email` | Formulaire « Écrire au club », envoyé par e-mail sans stockage |
| 11/11 | `chore/demo-data` | Données de démonstration réalistes (`DemoSeeder`) |
| 12/11 | `feature/invoice-pdf-i18n`, `feature/immediate-erasure` | Factures PDF en trois langues ; effacement immédiat du compte |
| 12/11 | `test/real-concurrency` | Vraie concurrence entre deux connexions à la base |
| 14/11 | `bugfix/align-docs-code` | Jetons d'API révoqués après « mot de passe oublié » ; balise noindex sur les pages privées |
| 14/11 | `feature/account-reactivation` | F4 : réactivation du compte par le joueur pendant trente jours |
| 14/11 | `feature/legal-consents` | F1 et F7 : date de naissance, niveau, parent sous 13 ans, versions des documents acceptées |
| 14/11 | `feature/close-sessions-on-password-change` | F3 : un nouveau mot de passe ferme les autres sessions |
| 14/11 | `feature/exceptional-requests` | F17 et F27 : demande pour un imprévu grave, pièce privée, décision motivée |
| 14/11 | `feature/account-suspension` | F28 et F29 : suspension motivée, contestation tranchée par un autre membre du personnel |
| 14/11 | `feature/counter-and-guest-access` | F25 et F18 : lien vers le QR code pour le comptoir et pour les invités |
| 14/11 | `feature/profile-and-pricing`, `feature/club-settings-and-staff` | F21 et F24 : profil, horaires du club, tarifs programmés |
| 14/11 | `feature/staff-accounts` | F35 : comptes du personnel et rôles |
| 14/11 | `feature/level-matching` | F11 : appel aux joueurs d'un niveau proche |
| 15/11 | `bugfix/refund-gaps`, `bugfix/terms-late-cancellation` | Remboursements partiels, annulation par le club, comptes suspendus ; annulation tardive dans les conditions |
| 17/11 | `fix/ci-inertia-page-paths` | Dossier des écrans écrit en minuscules dans la configuration des tests : Linux distingue la casse, Windows non |
| 18/11 | `feature/staff-i18n` | Écrans et messages du personnel en néerlandais et en anglais |
| 18/11 | `chore/docker` | Image de l'application avec FrankenPHP, sans root ; application, planificateur et PostgreSQL avec `docker compose` |
| 18/11 | `ci/dependency-audits` | `composer audit`, `npm audit` et Dependabot dans l'intégration continue ; failles connues des dépendances corrigées |
| 18/11 | `feature/store-credits` | F15 : avoirs. Une demande exceptionnelle peut être acceptée en avoir, l'avoir se déduit en priorité au paiement |
| 19/11 | `bugfix/failles-demande-et-qr` | La borne refuse le QR code d'un compte qui n'est plus actif ; demande refusée sur une réservation au comptoir |
| 19/11 | `feature/move-booking` | F14 : déplacer une réservation jusqu'à 24 h avant, différence de prix payée ou remboursée |
| 19/11 | `feature/freed-slot-waitlist` | Créneau d'une partie annulée proposé à sa liste d'attente, par e-mail |
| 20/11 | `feature/stripe-payments`, `bugfix/stripe-simulated-card` | Paiement sur la page de Stripe en mode test, carte de garantie, remboursements par Stripe ; paiement simulé sans clé |
| 22/11 | `feature/stripe-webhooks` | Notifications Stripe signées ; F30 : contestation bancaire, réservation gelée, compte signalé au gérant |
| 23/11 | `feature/abus-reservations` | Une adresse e-mail pour un seul compte ; dix paiements abandonnés en 24 h au plus ; conditions générales mises à jour |
| 24/11 | `feature/deploiement-render` | Fichier `render.yaml` : application, base PostgreSQL et secrets sur Render ; mot de passe de démonstration généré en ligne |
| 25/11 | `bugfix/camera-et-ip-joueur` | La borne peut ouvrir la caméra pour lire le QR code ; adresse du joueur lue seulement derrière Render et Cloudflare |
| 25/11 | `bugfix/php-upload-progress` | Option `session.upload_progress` coupée dans l'image de production (CVE-2026-115560) |
| 26/11 | `bugfix/symfony-cve-novembre` | Symfony 8.1.12 : cinq failles publiées le 25 novembre corrigées |

## Après la remise — version 1.1 (27 novembre au 11 décembre 2026)

Ces points suivent la remise du 26 novembre 2026 et précèdent la défense de mi-décembre.
Le rapport écrit les présente comme « version 1.1 ».

- F19 codes de secours de la borne, F31 suivi des absences, F33 export comptable.
- E-mail de confirmation de réservation, rappels et préférences de notification ; rappel avant la fin d'un avoir.
- Textes des pages, traductions, documents légaux et clés d'API modifiables par l'administrateur.
- Codes de secours de la double authentification ; tarif selon le jour ; adresse de facturation d'une entreprise.
- Suppression d'un compte jamais confirmé après sept jours ; recours contre une décision sur une demande exceptionnelle.
- Empreinte de la carte gardée sur le paiement, pour repérer une même carte sur plusieurs comptes.
- Adresse du joueur lue dans l'en-tête `CF-Connecting-IP` derrière Cloudflare.
- Contrôle complet WCAG 2.1 AA, tests de bout en bout avec Playwright, balises hreflang et plan du site.

## Idées à étudier plus tard

| Idée | Intérêt | Difficulté |
|---|---|---|
| Réservation récurrente | Le même créneau chaque semaine pour un groupe fixe | Moyenne |
| Événement privé | Plusieurs terrains en même temps pour une entreprise ou un anniversaire | Moyenne |

Écartée : une musique choisie par les joueurs. Diffuser de la musique dans un lieu public demande une licence payante (Unisono en Belgique).
