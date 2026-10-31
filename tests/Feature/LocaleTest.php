<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_interface_is_in_french_by_default(): void
    {
        $this->get('/')->assertSee('<html lang="fr">', false)->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
    }

    public function test_a_visitor_can_switch_to_dutch_and_english(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'nl'])->assertRedirect('/')->assertSessionHas('locale', 'nl');
        $this->get('/')->assertSee('<html lang="nl">', false)->assertInertia(fn (Assert $page) => $page->where('locale', 'nl'));

        $this->post('/locale', ['locale' => 'en']);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
    }

    public function test_a_player_finds_his_language_again_at_the_next_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/locale', ['locale' => 'en']);
        $this->assertSame('en', $user->fresh()->locale);

        // Nouvelle session : la langue vient du compte.
        $this->flushSession();
        $this->actingAs($user->fresh())->get('/planning')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }
}
