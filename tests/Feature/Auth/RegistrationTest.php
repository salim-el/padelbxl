<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_a_password_shorter_than_15_characters_is_refused()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'court@example.test',
            'password' => 'quatorze-car14',
            'password_confirmation' => 'quatorze-car14',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_a_new_account_receives_the_confirmation_email_it_needs_to_book()
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Nouveau Joueur',
            'email' => 'nouveau.joueur@example.test',
            'account_type' => 'individual',
            'password' => 'mot-de-passe-long-2026',
            'password_confirmation' => 'mot-de-passe-long-2026',
        ]);

        $user = User::where('email', 'nouveau.joueur@example.test')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->actingAs($user)->get('/bookings')->assertRedirect(route('verification.notice'));
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'account_type' => 'individual',
            'password' => 'mot-de-passe-long-2026',
            'password_confirmation' => 'mot-de-passe-long-2026',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
