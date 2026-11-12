<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vraie concurrence : une seconde connexion PostgreSQL joue l'autre session (le gérant qui ferme le terrain,
 * ou le même joueur dans un autre onglet). Elle prend le verrou avec le code de l'application et le garde ouvert.
 * La réservation de la première connexion doit attendre ; lock_timeout la fait échouer au lieu de bloquer le test.
 */
class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $other;

    private User $lucas;

    private Court $court1;

    private Court $court2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->court1 = Court::where('name', 'Terrain 1')->firstOrFail();
        $this->court2 = Court::where('name', 'Terrain 2')->firstOrFail();

        config(['database.connections.other_session' => config('database.connections.'.config('database.default'))]);
        $this->other = DB::connection('other_session');
        $this->other->beginTransaction();
        // Au-delà, l'attente compte comme un blocage : l'erreur 55P03 remplace une attente sans fin.
        DB::statement("set local lock_timeout = '300ms'");
    }

    protected function tearDown(): void
    {
        $this->other->rollBack();
        DB::purge('other_session');
        parent::tearDown();
    }

    /** L'autre session exécute le vrai code de verrouillage, sur sa propre connexion, et ne termine pas sa transaction. */
    private function otherSessionTakes(callable $lock): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('other_session');
        try {
            $lock();
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    private function assertWaitsForTheLock(callable $booking): void
    {
        try {
            $booking();
            $this->fail('La réservation est passée sans attendre le verrou de l’autre session.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode()); // lock_not_available : elle attendait bien
        }
    }

    public function test_a_booking_waits_while_a_closure_of_the_same_court_is_in_progress(): void
    {
        $slot = Clock::slotStart('2026-10-23', '18:00');
        $this->otherSessionTakes(fn () => BookingService::lockCourt($this->court1->id));

        $this->assertWaitsForTheLock(fn () => app(BookingService::class)->hold($this->lucas, $this->court1, $slot));
        $this->assertSame(0, Booking::count());

        // L'autre session termine : la réservation passe.
        $this->other->rollBack();
        $this->other->beginTransaction();
        $this->assertSame('pending_payment', app(BookingService::class)->hold($this->lucas, $this->court1, $slot)->status);
    }

    public function test_a_lock_on_one_court_does_not_block_the_others(): void
    {
        $this->otherSessionTakes(fn () => BookingService::lockCourt($this->court1->id));

        $booking = app(BookingService::class)->hold($this->lucas, $this->court2, Clock::slotStart('2026-10-23', '18:00'));

        $this->assertSame($this->court2->id, $booking->court_id);
    }

    public function test_two_bookings_of_the_same_player_pass_one_after_the_other(): void
    {
        // Le même joueur réserve dans un autre onglet : sa session tient le verrou du joueur, pas celui du terrain 2.
        $this->otherSessionTakes(fn () => BookingService::lockPlayer($this->lucas->id));

        $this->assertWaitsForTheLock(fn () => app(BookingService::class)->hold($this->lucas, $this->court2, Clock::slotStart('2026-10-23', '18:00')));

        // Un autre joueur n'attend pas.
        $ines = User::where('email', 'ines.laurent@example.test')->firstOrFail();
        $this->assertSame($ines->id, app(BookingService::class)->hold($ines, $this->court2, Clock::slotStart('2026-10-23', '18:00'))->organizer_id);
    }
}
