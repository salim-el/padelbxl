<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessService;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Données de démonstration : trois semaines passées et deux semaines à venir.
 * Tout passe par BookingService et AccessService, horloge déplacée à chaque étape :
 * factures, notes de crédit, QR codes et historiques sont ceux que l'application produit elle-même.
 *
 * php artisan db:seed --class=DemoSeeder, sur une base fraîche (après DatabaseSeeder).
 */
class DemoSeeder extends Seeder
{
    /** Colonnes remplies par la base (useCurrent) : sans recalage, elles prendraient l'heure réelle du seed. */
    private const DB_CLOCK = ['booking_players' => 'joined_at', 'payments' => 'created_at', 'refunds' => 'created_at', 'access_passes' => 'created_at', 'court_closures' => 'created_at'];

    /** @var list<array{0: CarbonImmutable, 1: int, 2: Closure}> */
    private array $events = [];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Booking> */
    private array $bookingsByKey = [];

    private CarbonImmutable $today;

    public function __construct(private BookingService $bookings, private AccessService $access) {}

    public function run(): void
    {
        if (Court::count() === 0) {
            $this->call(DatabaseSeeder::class);
        }
        $saved = Carbon::getTestNow();
        $this->today = CarbonImmutable::now(Clock::ZONE)->startOfDay();
        $this->schedule();
        // Ordre chronologique : la numérotation des factures suit les dates, comme en vrai.
        usort($this->events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        try {
            // Une seule transaction : une erreur ne laisse pas une base à moitié remplie.
            DB::transaction(function () {
                $last = [];
                foreach (array_keys(self::DB_CLOCK) as $table) {
                    $last[$table] = (int) DB::table($table)->max('id');
                }
                foreach ($this->events as [$when, , $do]) {
                    Carbon::setTestNow($when);
                    $do();
                    foreach (self::DB_CLOCK as $table => $column) {
                        DB::table($table)->where('id', '>', $last[$table])->update([$column => $when]);
                        $last[$table] = (int) DB::table($table)->max('id');
                    }
                }
            });
        } finally {
            Carbon::setTestNow($saved);
        }
    }

    private function schedule(): void
    {
        $this->at(-28, '10:00', function () {
            foreach (['lucas.martin', 'ines.laurent', 'marc.dumont'] as $login) {
                $this->users[strtok($login, '.')] = User::where('email', "$login@example.test")->firstOrFail();
            }
            foreach ([['Sarah Janssens', 'sarah'], ['Mehdi El Amrani', 'mehdi'], ['Lotte Wouters', 'lotte'], ['Antoine Dubois', 'antoine'],
                ['Nina Vermeulen', 'nina'], ['Julie Lambert', 'julie'], ['Pieter Claes', 'pieter']] as [$name, $key]) {
                $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test', 'password' => Hash::make(DatabaseSeeder::DEMO_PASSWORD)]);
                $user->forceFill(['email_verified_at' => now(), 'locale' => $key === 'lotte' || $key === 'pieter' ? 'nl' : 'fr'])->save();
                $this->users[$key] = $user;
            }
            // Client entreprise : numéro de TVA belge valide (97 − 08441234 mod 97 = 94).
            $this->users['pieter']->forceFill(['account_type' => 'company', 'company_name' => 'Studio Kanaal SRL', 'vat_number' => 'BE0844123494'])->save();
        });
        // Jeton de la borne du terrain 1, comme le crée la commande api:partner-token.
        $this->at(-25, '09:00', function () {
            $token = $this->users['marc']->createToken('Borne terrain 1', ['terminal'], now()->addYear());
            activity('api')->causedBy($this->users['marc'])->performedOn($token->accessToken)->event('device-token-created')->log('Jeton d’appareil créé');
        });

        // Passé : parties jouées, partagées, ouvertes, annulées, au comptoir.
        $this->book('a', 'lucas', 1, -21, '19:30', [-23, '18:05'], ['raquette' => 2]);
        $this->enter('a', 'lucas', -21, '19:22');

        $this->book('b', 'sarah', 2, -20, '12:00', [-22, '09:40']);
        $this->enter('b', 'sarah', -20, '11:52');

        // Partie partagée : un invité sans compte ne paie pas, la garantie de l'organisateur paie sa part à 24 h.
        $this->book('c', 'mehdi', 3, -19, '18:00', [-23, '21:00'], guests: ['lucas', 'nina', 'karim.haddad@example.test']);
        $this->payShare('c', 'lucas', -22, '10:00');
        $this->payShare('c', 'nina', -21, '08:15');
        $this->guarantee(-20, '18:05');
        $this->enter('c', 'mehdi', -19, '17:50');
        $this->enter('c', 'lucas', -19, '17:51');
        $this->enter('c', 'nina', -19, '17:52');
        $this->enter('c', 'nina', -19, '17:53'); // déjà utilisé

        // Partie ouverte : une place prise par un membre, l'autre payée par la garantie.
        $this->book('d', 'antoine', 4, -18, '21:00', [-22, '12:00'], open: 2);
        $this->join('d', 'lotte', -21, '19:00');
        $this->guarantee(-19, '21:05');
        $this->enter('d', 'antoine', -18, '20:50');
        $this->enter('d', 'lotte', -18, '20:51', court: 5); // mauvais terrain
        $this->enter('d', 'lotte', -18, '20:53');

        $this->book('e', 'julie', 1, -17, '10:30', [-19, '14:20']);
        $this->enter('e', 'julie', -17, '10:21');

        // Annulée plus de 24 h avant : remboursée, avec note de crédit.
        $this->book('f', 'ines', 2, -16, '16:30', [-20, '20:45']);
        $this->cancel('f', 'ines', -19, '09:10');

        // Annulée moins de 24 h avant : rien n'est remboursé, le QR code est révoqué.
        $this->book('g', 'lucas', 5, -15, '19:30', [-17, '12:30'], ['balles' => 1]);
        $this->cancel('g', 'lucas', -15, '10:00');
        $this->enter('g', 'lucas', -15, '19:25');

        // Au comptoir, moins de trois heures avant : prix flash.
        $this->counter('h', 6, -14, '18:00', [-14, '17:20'], 'Kevin Mertens', '+32 470 12 34 56', 'cash');

        $this->book('i', 'pieter', 3, -13, '12:00', [-15, '08:30'], ['raquette' => 2]);
        $this->enter('i', 'pieter', -13, '11:55');

        // Une invitée refuse, sa place devient ouverte et un membre la prend.
        $this->book('j', 'nina', 1, -12, '19:30', [-16, '19:00'], guests: ['sarah', 'ines']);
        $this->payShare('j', 'ines', -15, '12:40');
        $this->decline('j', 'sarah', -14, '10:00');
        $this->join('j', 'mehdi', -14, '18:30');
        $this->enter('j', 'nina', -12, '19:20');
        $this->enter('j', 'ines', -12, '19:21');
        $this->enter('j', 'mehdi', -12, '19:24');

        $this->book('k', 'lotte', 2, -11, '09:00', [-13, '21:15']);
        $this->enter('k', 'lotte', -11, '08:30'); // trop tôt
        $this->enter('k', 'lotte', -11, '08:50');

        // Créneau bloqué puis abandonné : la tâche planifiée le libère après dix minutes.
        $this->at(-12, '14:00', fn () => $this->bookings->hold($this->users['antoine'], $this->court(4), $this->moment(-10, '13:30')));
        $this->at(-12, '14:11', fn () => $this->bookings->expireHolds());

        $this->book('m', 'julie', 4, -9, '18:00', [-11, '17:00'], guests: ['lucas']);
        $this->payShare('m', 'lucas', -10, '09:30');
        $this->enter('m', 'julie', -9, '17:48');
        $this->enter('m', 'lucas', -9, '17:49');

        // Réservation du comptoir annulée la veille : remboursée sur place, note de crédit émise.
        $this->counter('n', 5, -8, '16:30', [-10, '11:00'], 'Sofie Maes', 'sofie.maes@example.test', 'terminal');
        $this->at(-9, '15:00', function () {
            $this->bookings->cancel($this->bookingsByKey['n'], $this->users['marc']);
            activity('staff')->causedBy($this->users['marc'])->performedOn($this->bookingsByKey['n'])->event('counter-booking-cancelled')->log('Réservation au comptoir annulée');
        });

        $this->book('o', 'mehdi', 2, -7, '19:30', [-9, '13:00'], ['raquette' => 4]);
        $this->enter('o', 'mehdi', -7, '19:18');

        $this->book('p', 'sarah', 1, -6, '21:00', [-8, '18:30']);
        $this->enter('p', 'sarah', -6, '20:55');
        $this->enter('p', 'sarah', -6, '22:40'); // créneau terminé

        // Désinscription d'un joueur qui a déjà joué : soft delete, anonymisé après 30 jours.
        $this->at(-5, '10:00', function () {
            $this->users['julie']->forceFill(['status' => 'deactivated'])->save();
            $this->users['julie']->delete();
        });

        $this->book('r', 'antoine', 3, -4, '18:00', [-6, '19:10']);
        $this->at(-4, '17:40', fn () => $this->access->validate('code-inconnu', $this->court(3)));
        $this->enter('r', 'antoine', -4, '17:47');

        $this->book('s', 'pieter', 6, -3, '12:00', [-5, '09:00'], ['balles' => 2]);
        $this->enter('s', 'pieter', -3, '11:50');

        $this->book('t', 'lotte', 4, -2, '19:30', [-5, '12:00'], guests: ['antoine'], open: 1);
        $this->payShare('t', 'antoine', -4, '08:00');
        $this->join('t', 'sarah', -4, '13:00');
        $this->enter('t', 'lotte', -2, '19:20');
        $this->enter('t', 'antoine', -2, '19:21');
        $this->enter('t', 'sarah', -2, '19:23');

        $this->book('u', 'ines', 1, -1, '18:00', [-3, '20:00']);
        $this->enter('u', 'ines', -1, '17:55');

        // Fermeture du terrain 6 pour entretien, sur une période sans réservation (comme AdminController::storeClosure).
        $this->at(-2, '11:00', function () {
            $closure = CourtClosure::create(['court_id' => $this->court(6)->id, 'starts_at' => $this->moment(4, '09:00'), 'ends_at' => $this->moment(4, '13:30'),
                'reason' => 'Entretien du revêtement', 'created_by' => $this->users['marc']->id]);
            activity('staff')->causedBy($this->users['marc'])->performedOn($closure)->event('closure-created')->log('Fermeture de terrain ajoutée');
        });

        // À venir : réservations faites ces derniers jours.
        $this->book('w1', 'lucas', 2, 1, '19:30', [-2, '20:00'], ['raquette' => 2]);
        // Une part payée, une invitation encore ouverte à une adresse sans compte.
        $this->book('w2', 'lucas', 1, 3, '18:00', [-1, '21:10'], guests: ['mehdi', 'elise.dupont@example.test']);
        $this->payShare('w2', 'mehdi', -1, '22:00');
        // Partie ouverte avec une place encore libre.
        $this->book('w3', 'antoine', 3, 5, '19:30', [-2, '12:00'], open: 2);
        $this->join('w3', 'nina', -1, '09:00');
        // Partie complète : Lucas n'a pas encore payé sa part, Lotte attend une place.
        $this->book('w4', 'sarah', 4, 6, '18:00', [-3, '18:00'], guests: ['lucas'], open: 1);
        $this->join('w4', 'mehdi', -2, '08:45');
        $this->wait('w4', 'lotte', -1, '10:00');
        // Invitation refusée : la place est proposée aux membres.
        $this->book('w5', 'mehdi', 5, 7, '21:00', [-2, '13:30'], guests: ['nina']);
        $this->decline('w5', 'nina', -1, '11:15');
        $this->book('w10', 'nina', 2, 8, '18:00', [-4, '10:00']);
        $this->cancel('w10', 'nina', -2, '16:00');
        $this->counter('w6', 2, 9, '12:00', [-1, '15:00'], 'Bram De Smet', '0478 55 21 09', 'terminal');
        $this->book('w7', 'pieter', 6, 10, '19:30', [-1, '08:20']);
        $this->book('w8', 'ines', 1, 12, '18:00', [-2, '19:40'], ['balles' => 1]);
        $this->book('w9', 'lotte', 3, 14, '16:30', [-1, '20:30']);
    }

    /** Ajoute une étape à la date locale « aujourd'hui + $day » et à l'heure $time. */
    private function at(int $day, string $time, Closure $do): void
    {
        $this->events[] = [$this->moment($day, $time), count($this->events), $do];
    }

    private function moment(int $day, string $time): CarbonImmutable
    {
        return Clock::slotStart($this->today->addDays($day)->toDateString(), $time);
    }

    private function court(int $number): Court
    {
        return Court::where('name', "Terrain $number")->firstOrFail();
    }

    /**
     * Réserve et paie au moment $at = [jour, heure].
     *
     * @param  array{0: int, 1: string}  $at
     * @param  array<string, int>  $items  « raquette » ou « balles » => quantité
     * @param  list<string>  $guests  clé d'un joueur de la démo, ou e-mail d'un invité sans compte
     */
    private function book(string $key, string $who, int $court, int $day, string $time, array $at, array $items = [], array $guests = [], int $open = 0): void
    {
        $this->at($at[0], $at[1], function () use ($key, $who, $court, $day, $time, $items, $guests, $open) {
            $user = $this->users[$who];
            $products = ['raquette' => 'Location de raquette', 'balles' => 'Tube de 3 balles neuves'];
            $quantities = [];
            foreach ($items as $item => $quantity) {
                $quantities[Product::where('name', $products[$item])->value('id')] = $quantity;
            }
            $emails = array_map(fn ($g) => isset($this->users[$g]) ? $this->users[$g]->email : $g, $guests);
            $hold = $this->bookings->hold($user, $this->court($court), $this->moment($day, $time));
            $this->bookingsByKey[$key] = $this->bookings->confirmPayment($hold, $user, $quantities, 'card', $emails, $open);
        });
    }

    /** @param array{0: int, 1: string} $at */
    private function counter(string $key, int $court, int $day, string $time, array $at, string $name, string $contact, string $method): void
    {
        $this->at($at[0], $at[1], function () use ($key, $court, $day, $time, $name, $contact, $method) {
            $booking = $this->bookings->counter($this->users['marc'], $this->court($court), $this->moment($day, $time), $name, $contact, $method);
            activity('staff')->causedBy($this->users['marc'])->performedOn($booking)->event('counter-booking-created')
                ->withProperties(['method' => $method])->log('Réservation au comptoir');
            $this->bookingsByKey[$key] = $booking;
        });
    }

    private function payShare(string $key, string $who, int $day, string $time): void
    {
        $this->at($day, $time, fn () => $this->bookings->payShare($this->bookingsByKey[$key], $this->users[$who]));
    }

    private function join(string $key, string $who, int $day, string $time): void
    {
        $this->at($day, $time, fn () => $this->bookings->joinOpenMatch($this->bookingsByKey[$key], $this->users[$who]));
    }

    private function wait(string $key, string $who, int $day, string $time): void
    {
        $this->at($day, $time, fn () => $this->bookings->joinWaitlist($this->bookingsByKey[$key], $this->users[$who]));
    }

    private function decline(string $key, string $who, int $day, string $time): void
    {
        $this->at($day, $time, fn () => $this->bookings->declineShare($this->bookingsByKey[$key], $this->users[$who]));
    }

    private function cancel(string $key, string $who, int $day, string $time): void
    {
        $this->at($day, $time, fn () => $this->bookings->cancel($this->bookingsByKey[$key], $this->users[$who]));
    }

    /** Passage de la tâche planifiée bookings:charge-unpaid-shares. */
    private function guarantee(int $day, string $time): void
    {
        $this->at($day, $time, fn () => $this->bookings->chargeUnpaidShares());
    }

    /** Le joueur présente son QR code à la borne, par défaut sur le terrain de sa réservation. */
    private function enter(string $key, string $who, int $day, string $time, ?int $court = null): void
    {
        $this->at($day, $time, function () use ($key, $who, $court) {
            $booking = $this->bookingsByKey[$key];
            $player = $booking->players()->where('user_id', $this->users[$who]->id)->firstOrFail();
            $this->access->validate(BookingService::passToken($player), $court ? $this->court($court) : $booking->court);
        });
    }
}
