# ArenaBxl

Application web de l'Arena Padel Club, un club fictif à Anderlecht. Il a six terrains de padel et quatre terrains de foot à 5, tous couverts.
Elle gère la réservation en ligne et au comptoir, le paiement, l'accès au terrain par QR code et la facturation.
Épreuve intégrée, Bachelier en informatique, orientation développement d'applications, ICC Bruxelles, 2025-2026.
Auteur : EL GHALBZOURI Salim.

## État de la version remise

Le code du projet est gelé depuis le 25 novembre 2026, au commit `0fa88db`.
Le 26 novembre 2026, Symfony passe à la version 8.1.12 pour corriger cinq failles publiées la veille.
L'étiquette `v1.0.0` marque cette version sur `main`. Le rapport écrit est remis le 26 novembre 2026.
La suite compte 294 tests et 1796 vérifications. Ils passent tous.

Le fichier `render.yaml` décrit la mise en ligne sur Render : l'application, sa base PostgreSQL et ses secrets.
Le paiement passe par Stripe en mode test quand les clés de test sont présentes. Sans clé, il est simulé.
Aucun argent réel n'est encaissé.

## Ce que fait la version remise

Pour le joueur :

- Planning public des six terrains, sans donnée personnelle.
- Réservation d'un créneau de 90 minutes, bloqué 10 minutes le temps de payer. Trois réservations à venir au plus.
- Créneau flash à 20 % de moins quand il commence dans moins de trois heures.
- Paiement sur la page de Stripe en mode test, ou simulé sans clé. Facture numérotée sans trou, en PDF. Facture électronique UBL pour une entreprise.
- Partage du prix entre les joueurs invités, quatre au plus. Les centimes qui ne se divisent pas restent à l'organisateur.
- Parts impayées débitées à l'organisateur 24 h avant le match, sur sa carte gardée en garantie.
- Places ouvertes aux membres du club, liste d'attente prévenue quand une place se libère dans une partie.
- Créneau d'une partie annulée proposé à sa liste d'attente, par e-mail.
- Location et vente de matériel.
- QR code personnel, valable de 15 minutes avant le créneau jusqu'à sa fin.
- Annulation remboursée jusqu'à 24 h avant, avec note de crédit. Déplacement vers un autre créneau jusqu'à 24 h avant.
- Demande exceptionnelle pour un imprévu grave, acceptée en remboursement ou en avoir. L'avoir se déduit au paiement suivant.
- Une adresse e-mail sert à un seul compte. Dix paiements abandonnés en 24 h au plus.
- Interface en français, en néerlandais et en anglais.
- Export de ses données, désactivation du compte, réactivation pendant 30 jours, effacement immédiat.

Pour le personnel :

- Tableau de bord du gérant, avec les chiffres et les réservations du jour.
- Réservation au comptoir pour un client sans compte, ticket avec QR code.
- Tarifs, horaires et fermeture des terrains.
- Annulation de toute réservation, avec remboursement complet.
- Décision sur les demandes exceptionnelles et sur les contestations de suspension.
- Suspension des comptes. Journal des actions sensibles, gardé un an.
- Double authentification obligatoire. Comptes du personnel et rôles gérés par l'administratrice.
- Borne d'accès simulée. Le code se saisit à la main. Un bouton ouvre la caméra dans un navigateur qui sait lire un QR code.
- Contestation bancaire reçue de Stripe : réservation gelée, compte signalé au gérant, signalement à classer.
- Écrans du personnel en français, en néerlandais et en anglais.

Pour les logiciels partenaires :

- API REST `/api/v1` avec des jetons Sanctum. Un jeton de joueur expire après 30 jours, un jeton d'appareil du club après un an.
- Créneaux libres en données ouvertes, au format JSON-LD, sous licence CC BY 4.0.

## Le foot à 5, ajouté après la remise

La branche `develop` ajoute le foot à 5 en salle. Le padel ne change pas.

- Quatre terrains couverts, de « Foot 1 » à « Foot 4 », à côté des six terrains de padel.
- Créneaux d'une heure, de 9 h à 23 h. Le planning demande d'abord le sport, puis passe de l'un à l'autre par des onglets.
- Dix joueurs par match. L'organisateur partage le prix avec neuf invités au plus.
- Heures creuses à 70 € de 9 h à 17 h, heures pleines à 90 € de 17 h à 23 h, TVA de 6 % comprise. La remise flash vaut aussi pour le foot.
- Ni place ouverte aux membres ni liste d'attente. Une invitation refusée reste à la charge de l'organisateur, prévenu par e-mail. Sa garantie la paie 24 h avant le match.
- Avec Stripe, un match partagé demande une carte de garantie, même quand l'avoir couvre la part de l'organisateur.
- La raquette et les balles restent au padel. Une réservation ne se déplace que sur un terrain du même sport.
- Les conditions générales passent en version 2026.2. Chaque joueur les accepte avant sa prochaine réservation.
- `GET /api/v1/courts` donne chaque terrain avec son champ `sport`, qui vaut `padel` ou `foot5`.
- `GET /api/v1/availability?date=AAAA-MM-JJ&sport=foot5` donne tous les créneaux du jour d'un sport, libres ou non (champ `available`). Sans `sport`, c'est le padel. Un autre sport répond 422.
- Les données ouvertes restent celles du padel.

## Ce qui suit en version 1.1

Ces points viennent après la remise, avant la défense de mi-décembre 2026.

- Codes de secours de la borne (F19), suivi des absences (F31), export comptable (F33).
- E-mail de confirmation de réservation, rappels et préférences de notification. Rappel avant la fin d'un avoir.
- Textes des pages, traductions, documents légaux et clés d'API modifiables par l'administrateur.
- Codes de secours de la double authentification. Tarif selon le jour. Adresse de facturation d'une entreprise.
- Suppression d'un compte jamais confirmé après sept jours. Recours contre une décision sur une demande exceptionnelle.
- Empreinte de la carte gardée sur le paiement, pour repérer une même carte sur plusieurs comptes.
- Contrôle complet WCAG 2.1 AA, tests de bout en bout avec Playwright, balises hreflang et plan du site.

## Technologies

Laravel 13 (PHP 8.4.27 au moins), React 19 et TypeScript 5.7 avec Inertia 2, Tailwind CSS 4, Vite 6, PostgreSQL 16.
Tests avec PHPUnit 11. Style vérifié par Pint, ESLint et Prettier.
Failles connues des dépendances contrôlées à chaque envoi par `composer audit` et `npm audit`. Dependabot propose les mises à jour.

## Installation

Prérequis : PHP 8.4.27 ou plus récent, Composer, Node 22, PostgreSQL 16.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Régler `DB_DATABASE`, `DB_USERNAME` et `DB_PASSWORD` dans `.env` avant la migration.
L'application répond sur http://localhost:8000.

Pour la démonstration, une seconde commande remplit toutes les tables avec un historique réaliste.
Elle couvre trois semaines passées et deux semaines à venir : parties payées, partagées et ouvertes, liste d'attente,
annulations remboursées ou tardives, comptoir, fermeture de terrain, client entreprise, passages à la borne.
Elle ajoute trois matchs de foot à 5 d'une heure. Deux sont déjà joués, celui d'une entreprise à midi et un match à dix joueurs.
Le troisième est à venir et partage aussi le prix entre dix joueurs.
Elle passe par les règles de l'application et se lance une fois, sur une base fraîche, après `migrate --seed` :

```bash
php artisan db:seed --class=DemoSeeder
```

## Lancer avec Docker

Prérequis : Docker avec Compose. Le fichier `.env` doit contenir `APP_KEY` et `DB_PASSWORD`.

```bash
docker compose up --build
docker compose exec app php artisan db:seed --force
```

L'application répond sur http://localhost:8080. Le conteneur `app` met le schéma de la base à jour à chaque démarrage.
Le conteneur `scheduler` lance les tâches planifiées. PostgreSQL 16 garde ses données dans le volume `donnees`.
`docker compose down -v` arrête tout et efface ce volume.

## Restaurer la base de démonstration

L'archive du code remise avec le rapport contient aussi `T14-dump_padelbxl.sql`, le dump de cette base.
Sa restauration demande PostgreSQL 16.10 ou plus récent. Mettre d'abord `DB_DATABASE=padelbxl` dans `.env`.

```bash
createdb padelbxl
psql -d padelbxl -f T14-dump_padelbxl.sql
php artisan migrate
```

Le dump date de la version remise. Sans `migrate`, l'accueil et le planning ne s'ouvrent pas.
`migrate` y ajoute le foot à 5, avec la colonne `sport`, quatre terrains et deux tarifs. Il publie aussi les conditions générales 2026.2.
Chaque compte de démonstration les accepte à sa prochaine réservation.

## Comptes de démonstration

| Compte | Rôle |
|---|---|
| lucas.martin@example.test | Joueur |
| ines.laurent@example.test | Joueuse |
| thomas.peeters@example.test | Joueur dont l'e-mail n'est pas encore confirmé |
| yassin.benali@example.test | Joueur suspendu |
| marc.dumont@example.test | Gérant, accès à la borne |
| claire.vermeulen@example.test | Administratrice : comptes du personnel et rôles |
| sarah.janssens@example.test, mehdi.el.amrani@example.test, lotte.wouters@example.test… | Joueurs ajoutés par `DemoSeeder` |
| pieter.claes@example.test | Client entreprise (`DemoSeeder`), factures avec numéro de TVA |
| julie.lambert@example.test | Compte désactivé (`DemoSeeder`), réactivable pendant 30 jours |

Mot de passe : `Padeltfeicc2026#`. Réservé à la démonstration en local.
En ligne, Render génère un autre mot de passe. Celui du dépôt y est refusé.

## Tests

Les tests tournent sur une base PostgreSQL nommée `padelbxl_test`.

```bash
php artisan test
```

## Branches et feuille de route

Les conventions de branches et de commits, l'historique des versions et la suite sont dans [ROADMAP.md](ROADMAP.md).
