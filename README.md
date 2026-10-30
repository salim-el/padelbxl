# PadelBxl

Application de réservation de terrains pour l'Arena Padel Club (6 terrains, Anderlecht).
Épreuve intégrée, Bachelier en informatique de gestion, ICC Bruxelles, 2026-2027.
Auteur : EL GHALBZOURI Salim.

## Ce que fait la version alpha

- Planning public des 6 terrains, sans donnée personnelle.
- Réservation d'un créneau de 90 minutes, bloqué 10 minutes le temps de payer.
- Paiement simulé, facture numérotée sans trou, TVA de 6 %.
- QR code personnel, valable 15 minutes avant le créneau et jusqu'à sa fin.
- Annulation remboursée jusqu'à 24 h avant, avec note de crédit.
- Borne d'accès simulée, réservée au personnel.
- Tableau de bord du gérant : chiffres du jour, réservations du jour, comptes et suspension.

## Technologies

Laravel 13 (PHP 8.4), React 19 et TypeScript avec Inertia 2, Tailwind 4, PostgreSQL 16.

## Installation

Prérequis : PHP 8.4, Composer, Node 22, PostgreSQL 16.

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

## Comptes de démonstration

| Compte | Rôle |
|---|---|
| lucas.martin@example.test | Joueur |
| marc.dumont@example.test | Gérant, accès à la borne |

Mot de passe : `Padeltfeicc2026#`. Réservé à la démonstration.

## Tests

Les tests tournent sur une base PostgreSQL nommée `padelbxl_test`.

```bash
php artisan test
```

## Branches et feuille de route

Les conventions de branches et de commits, et le planning de la version bêta, sont dans [ROADMAP.md](ROADMAP.md).
