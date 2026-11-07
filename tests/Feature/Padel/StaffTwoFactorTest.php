<?php

namespace Tests\Feature\Padel;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StaffTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $marc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['auth.staff_two_factor' => true]);
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
    }

    private function enable(User $user): string
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        return $secret;
    }

    public function test_staff_without_two_factor_are_sent_to_the_setup_screen(): void
    {
        $this->actingAs($this->marc)->get('/admin')->assertRedirect('/settings/two-factor');
        $this->actingAs($this->marc)->get('/kiosk')->assertRedirect('/settings/two-factor');
        $this->enable($this->marc);
        $this->actingAs($this->marc)->withSession(['two_factor.passed' => true])->get('/admin')->assertOk();
    }

    public function test_a_session_opened_without_the_code_must_still_give_it(): void
    {
        // Session ouverte avant l'activation, ou par le cookie « rester connecté » : le code n'a jamais été saisi.
        $secret = $this->enable($this->marc);
        $this->actingAs($this->marc)->get('/admin/journal')->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect('/admin/journal');
        $this->assertAuthenticatedAs($this->marc);
        $this->get('/admin/journal')->assertOk();
    }

    public function test_the_manager_activates_two_factor_with_a_code_from_the_app(): void
    {
        $this->actingAs($this->marc)->get('/settings/two-factor')->assertOk();
        $secret = session('two_factor.setup');

        $this->actingAs($this->marc)->post('/settings/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($this->marc)->post('/settings/two-factor', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasNoErrors();

        $this->assertTrue($this->marc->fresh()->hasTwoFactor());
        // Le secret est chiffré en base : une copie de la base ne suffit pas à générer les codes.
        $this->assertNotSame($secret, DB::table('users')->where('id', $this->marc->id)->value('two_factor_secret'));
        $this->assertSame('two-factor-enabled', Activity::sole()->event);
    }

    public function test_without_the_code_the_staff_cannot_reach_any_page_not_even_the_settings(): void
    {
        $this->enable($this->marc);
        foreach (['/settings/profile', '/settings/password', '/planning'] as $url) {
            $this->actingAs($this->marc)->get($url)->assertRedirect('/two-factor-challenge');
        }
    }

    public function test_turning_two_factor_off_needs_both_the_password_and_a_code(): void
    {
        $secret = $this->enable($this->marc);
        $session = $this->actingAs($this->marc)->withSession(['two_factor.passed' => true]);

        $session->delete('/settings/two-factor', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasErrors('password');
        $session->delete('/settings/two-factor', ['password' => DatabaseSeeder::DEMO_PASSWORD, 'code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasNoErrors();
        $this->assertFalse($this->marc->fresh()->hasTwoFactor());
    }

    public function test_a_secret_left_in_another_session_cannot_replace_the_active_one(): void
    {
        $this->actingAs($this->marc)->get('/settings/two-factor');
        $stale = session('two_factor.setup');
        $this->enable($this->marc);
        $before = $this->marc->fresh()->two_factor_secret;

        $this->actingAs($this->marc)->withSession(['two_factor.setup' => $stale])
            ->post('/settings/two-factor', ['code' => (new Google2FA)->getCurrentOtp($stale)])->assertStatus(409);
        $this->assertSame($before, $this->marc->fresh()->two_factor_secret);
    }

    public function test_login_asks_for_the_code_before_opening_the_session(): void
    {
        $secret = $this->enable($this->marc);

        $this->post('/login', ['email' => $this->marc->email, 'password' => DatabaseSeeder::DEMO_PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->marc);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $code = (new Google2FA)->getCurrentOtp($this->enable($this->marc));
        $this->post('/login', ['email' => $this->marc->email, 'password' => DatabaseSeeder::DEMO_PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->post('/login', ['email' => $this->marc->email, 'password' => DatabaseSeeder::DEMO_PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_after_five_wrong_codes_the_account_is_sent_back_to_the_password_and_locked_for_a_while(): void
    {
        $secret = $this->enable($this->marc);
        $this->post('/login', ['email' => $this->marc->email, 'password' => DatabaseSeeder::DEMO_PASSWORD]);
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/login');

        // Même avec le bon mot de passe puis le bon code, le compte reste bloqué un quart d'heure.
        $this->post('/login', ['email' => $this->marc->email, 'password' => DatabaseSeeder::DEMO_PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_challenge_expires_after_a_few_minutes(): void
    {
        $this->enable($this->marc);
        $this->post('/login', ['email' => $this->marc->email, 'password' => DatabaseSeeder::DEMO_PASSWORD]);
        $this->travel(6)->minutes();

        $this->get('/two-factor-challenge')->assertRedirect('/login');
    }

    public function test_the_challenge_cannot_be_reached_without_a_password_first(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');
    }

    public function test_players_neither_see_nor_need_two_factor(): void
    {
        $lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
        $this->actingAs($lucas)->get('/settings/two-factor')->assertForbidden();
        $this->post('/logout');

        $this->post('/login', ['email' => $lucas->email, 'password' => DatabaseSeeder::DEMO_PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($lucas);
    }

    public function test_a_password_found_in_a_data_breach_is_refused(): void
    {
        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Nina Peeters',
            'email' => 'nina.peeters@example.test',
            'password' => self::LEAKED_PASSWORD,
            'password_confirmation' => self::LEAKED_PASSWORD,
        ])->assertSessionHasErrors('password');
        $this->assertGuest();
    }
}
