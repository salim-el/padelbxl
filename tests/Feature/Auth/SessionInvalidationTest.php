<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** F3 : toutes les sessions ouvertes sont fermées quand le mot de passe change. */
class SessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_password_closes_the_sessions_already_open(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        // Session ouverte sur un autre appareil : son empreinte du mot de passe est enregistrée.
        $this->actingAs($user)->get('/settings/profile')->assertOk();
        $device = $this->app['session']->all();

        Auth::logout();
        $this->flushSession();
        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
            $this->post('/reset-password', ['token' => $n->token, 'email' => $user->email,
                'password' => 'mot-de-passe-long-2026', 'password_confirmation' => 'mot-de-passe-long-2026'])->assertSessionHasNoErrors();

            return true;
        });

        // Retour sur l'autre appareil avec son ancienne session.
        $this->actingAs($user->fresh())->withSession($device)->get('/settings/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_changing_the_password_from_the_profile_keeps_the_current_session_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/profile')->assertOk();
        $other = $this->app['session']->all();

        $this->put('/settings/password', ['current_password' => 'password',
            'password' => 'nouveau-mot-de-passe-2026', 'password_confirmation' => 'nouveau-mot-de-passe-2026'])->assertSessionHasNoErrors();

        // La session qui a fait le changement continue.
        $this->get('/settings/profile')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('nouveau-mot-de-passe-2026', $user->fresh()->password));

        // Une autre session gardait l'ancienne empreinte : elle est fermée.
        $this->flushSession();
        $this->actingAs($user->fresh())->withSession($other)->get('/settings/profile')->assertRedirect('/login');
        $this->assertGuest();
    }
}
