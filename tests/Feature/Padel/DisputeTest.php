<?php

namespace Tests\Feature\Padel;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CreditNote;
use App\Models\Dispute;
use App\Models\User;
use App\Notifications\DisputeDecided;
use App\Services\BookingService;
use App\Services\Clock;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** F17 et F27 : demande exceptionnelle du joueur, décision motivée du gérant. */
class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Hospitalisation la veille du match, certificat joint.';

    private User $lucas;

    private User $marc;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->lucas = User::where('email', 'lucas.martin@example.test')->first();
        $this->marc = User::where('email', 'marc.dumont@example.test')->first();
        $service = app(BookingService::class);
        // Partie de 19 h 30 le 23/10, aux heures pleines : 48 €.
        $this->booking = $service->confirmPayment($service->hold($this->lucas, Court::where('name', 'Terrain 1')->first(), Clock::slotStart('2026-10-23', '19:30')), $this->lucas);
        // Le lendemain du match : plus aucune annulation remboursée possible.
        $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00', Clock::ZONE));
    }

    private function open(): Dispute
    {
        return app(DisputeService::class)->open($this->lucas, $this->booking, self::REASON, null);
    }

    public function test_a_player_files_a_request_with_a_private_attachment_and_follows_it(): void
    {
        $file = UploadedFile::fake()->create('certificat.pdf', 120, 'application/pdf');
        $this->actingAs($this->lucas)->post("/bookings/{$this->booking->getRouteKey()}/dispute", ['reason' => self::REASON, 'attachment' => $file])
            ->assertRedirect('/disputes');

        $dispute = Dispute::firstOrFail();
        $this->assertSame('received', $dispute->status);
        Storage::disk('local')->assertExists($dispute->attachment_path);
        $this->actingAs($this->lucas)->get('/disputes')->assertInertia(fn ($p) => $p->component('disputes/index')->where('disputes.0.status', 'received'));

        // La pièce n'est servie qu'au personnel.
        $this->actingAs($this->lucas)->get("/admin/disputes/{$dispute->id}/attachment")->assertForbidden();
        $this->actingAs($this->marc)->get("/admin/disputes/{$dispute->id}/attachment")->assertOk();
    }

    public function test_one_request_per_booking_within_seven_days_and_only_for_a_payer(): void
    {
        $url = "/bookings/{$this->booking->getRouteKey()}/dispute";
        $ines = User::where('email', 'ines.laurent@example.test')->first();

        $this->actingAs($ines)->get($url)->assertNotFound();
        $this->actingAs($this->lucas)->post($url, ['reason' => 'trop court'])->assertSessionHasErrors('reason');
        $this->actingAs($this->lucas)->post($url, ['reason' => self::REASON])->assertRedirect('/disputes');
        $this->actingAs($this->lucas)->post($url, ['reason' => self::REASON])->assertSessionHasErrors('reason');
        $this->assertSame(1, Dispute::count());

        // Plus de sept jours après le match : refusé.
        Dispute::query()->delete();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', Clock::ZONE));
        $this->actingAs($this->lucas)->post($url, ['reason' => self::REASON])->assertSessionHasErrors('reason');
    }

    public function test_opening_puts_the_request_in_review_and_a_full_acceptance_refunds_with_a_credit_note(): void
    {
        $dispute = $this->open();

        $this->actingAs($this->marc)->get("/admin/disputes/{$dispute->id}")->assertOk();
        $this->assertSame('in_review', $dispute->fresh()->status);

        $this->actingAs($this->marc)->post("/admin/disputes/{$dispute->id}/decision", ['decision' => 'accepted_full', 'decision_note' => 'Certificat médical valable.'])
            ->assertRedirect('/admin/disputes');

        $dispute->refresh();
        $this->assertSame('accepted_full', $dispute->status);
        $this->assertSame(4800, $dispute->refunded_cents);
        $this->assertSame($this->marc->id, $dispute->decided_by);
        $note = CreditNote::firstOrFail();
        $this->assertSame(4800, $note->total_cents);
        $this->assertSame($note->total_cents, $note->net_cents + $note->vat_cents);
        Notification::assertSentTo($this->lucas, DisputeDecided::class);
        $this->assertDatabaseHas('activity_log', ['event' => 'dispute-decided', 'causer_id' => $this->marc->id]);
    }

    public function test_a_partial_refund_splits_vat_like_the_invoice_and_cannot_exceed_what_was_paid(): void
    {
        $dispute = $this->open();
        $decide = fn (array $d) => $this->actingAs($this->marc)->post("/admin/disputes/{$dispute->id}/decision", $d + ['decision_note' => 'Geste commercial, moitié du prix.']);

        $decide(['decision' => 'accepted_partial', 'amount' => '48.00'])->assertSessionHasErrors('decision');
        $decide(['decision' => 'accepted_partial'])->assertSessionHasErrors('amount');
        $decide(['decision' => 'accepted_partial', 'amount' => '24.00'])->assertRedirect('/admin/disputes');

        $note = CreditNote::firstOrFail();
        $this->assertSame(2400, $note->total_cents);
        // TVA de 6 % sur l'accès au terrain : 24 € TTC = 22,64 € hors TVA + 1,36 € de TVA.
        $this->assertSame(2264, $note->net_cents);
        $this->assertSame('partially_refunded', $this->booking->payments()->first()->status);
        // Une décision ne se prend qu'une fois.
        $decide(['decision' => 'rejected'])->assertSessionHasErrors('decision');
    }

    public function test_a_rejection_needs_a_motivation_and_refunds_nothing(): void
    {
        $dispute = $this->open();
        $url = "/admin/disputes/{$dispute->id}/decision";

        $this->actingAs($this->marc)->post($url, ['decision' => 'rejected', 'decision_note' => ''])->assertSessionHasErrors('decision_note');
        $this->actingAs($this->marc)->post($url, ['decision' => 'rejected', 'decision_note' => 'Aucun justificatif d’un imprévu grave.'])->assertRedirect();

        $this->assertSame('rejected', $dispute->fresh()->status);
        $this->assertSame(0, CreditNote::count());
        Notification::assertSentTo($this->lucas, DisputeDecided::class);
    }

    public function test_players_cannot_decide(): void
    {
        $dispute = $this->open();

        $this->actingAs($this->lucas)->get('/admin/disputes')->assertForbidden();
        $this->actingAs($this->lucas)->post("/admin/disputes/{$dispute->id}/decision", ['decision' => 'accepted_full', 'decision_note' => 'Je m’accepte moi-même.'])->assertForbidden();
        $this->assertSame('received', $dispute->fresh()->status);
    }
}
