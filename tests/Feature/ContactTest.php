<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ContactMessage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private array $message = ['name' => 'Julie Simon', 'email' => 'julie.simon@example.test', 'message' => 'Puis-je réserver un terrain pour un tournoi interne ?'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['invoicing.seller.email' => 'accueil@club.example.test']);
        Notification::fake();
    }

    public function test_a_visitor_writes_to_the_club_and_nothing_is_stored(): void
    {
        $this->get('/contact')->assertOk()->assertInertia(fn (Assert $page) => $page->component('contact')->where('sent', false));

        $this->post('/contact', $this->message)->assertRedirect('/contact')->assertSessionHas('contact_sent', true);

        Notification::assertSentTo(new AnonymousNotifiable, ContactMessage::class, function (ContactMessage $n, array $channels, AnonymousNotifiable $to) {
            $mail = $n->toMail($to);

            return $to->routes['mail'] === 'accueil@club.example.test' && $n->locale === 'fr'
                && $mail->replyTo === [['julie.simon@example.test', 'Julie Simon']]
                && $mail->viewData['body'] === 'Puis-je réserver un terrain pour un tournoi interne ?';
        });
        // Aucune table ne reçoit le message : pas de durée de conservation à gérer.
        $this->assertDatabaseMissing('activity_log', ['description' => 'Puis-je réserver un terrain pour un tournoi interne ?']);
    }

    public function test_a_link_typed_by_the_visitor_stays_plain_text_in_the_mail(): void
    {
        $mail = (new ContactMessage("Julie\r\nBcc: x@example.test", 'julie.simon@example.test', "[Payez ici](https://exemple.test)\n<b>urgent</b>"))->toMail(new AnonymousNotifiable);

        $html = (string) $mail->render();
        $this->assertStringContainsString('[Payez ici](https://exemple.test)', $html);
        $this->assertStringContainsString('&lt;b&gt;urgent&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString("\n", $mail->subject);
    }

    public function test_a_logged_in_player_finds_his_name_and_email_already_filled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();

        $this->actingAs($lucas)->get('/contact')->assertInertia(fn (Assert $page) => $page->where('auth.user.email', 'lucas.martin@example.test'));
    }

    public function test_an_incomplete_message_is_refused(): void
    {
        $this->post('/contact', ['name' => '', 'email' => 'pas-une-adresse', 'message' => 'court'])->assertSessionHasErrors(['name', 'email', 'message']);

        Notification::assertNothingSent();
    }

    public function test_three_messages_per_minute_at_most(): void
    {
        foreach (range(1, 3) as $i) {
            $this->post('/contact', $this->message)->assertRedirect('/contact');
        }

        $this->post('/contact', $this->message)->assertTooManyRequests();
    }

    public function test_without_a_club_email_the_form_is_closed(): void
    {
        config(['invoicing.seller.email' => null]);

        $this->post('/contact', $this->message)->assertServiceUnavailable();
        Notification::assertNothingSent();
    }
}
