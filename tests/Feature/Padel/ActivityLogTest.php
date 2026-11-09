<?php

namespace Tests\Feature\Padel;

use App\Models\Court;
use App\Models\CourtClosure;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $marc;

    private User $lucas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', Clock::ZONE));
        $this->marc = User::where('email', 'marc.dumont@example.test')->firstOrFail();
        $this->lucas = User::where('email', 'lucas.martin@example.test')->firstOrFail();
    }

    public function test_the_manager_actions_on_accounts_are_logged_with_their_author(): void
    {
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/suspend");
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/reactivate");

        $this->assertSame(['account-suspended', 'account-reactivated'], Activity::inLog('staff')->orderBy('id')->pluck('event')->all());
        $entry = Activity::inLog('staff')->first();
        $this->assertTrue($entry->causer->is($this->marc));
        $this->assertTrue($entry->subject->is($this->lucas));
    }

    public function test_a_price_change_keeps_the_old_and_new_price(): void
    {
        $rule = PricingRule::where('name', 'Heures pleines')->firstOrFail();
        $old = $rule->price_cents;

        $this->actingAs($this->marc)->patch("/admin/pricing-rules/{$rule->id}", ['price_cents' => $old + 500]);

        $entry = Activity::where('event', 'price-updated')->sole();
        $this->assertSame(['old_cents' => $old, 'new_cents' => $old + 500], $entry->properties->all());
    }

    public function test_court_closures_are_logged_when_added_and_removed(): void
    {
        $court = Court::where('name', 'Terrain 2')->firstOrFail();
        $this->actingAs($this->marc)->post('/admin/closures', ['court_id' => $court->id, 'from' => '2026-12-01T08:00', 'to' => '2026-12-01T12:00', 'reason' => 'Entretien']);
        $closure = CourtClosure::sole();
        $this->actingAs($this->marc)->delete("/admin/closures/{$closure->id}");

        $this->assertSame(['closure-created', 'closure-deleted'], Activity::orderBy('id')->pluck('event')->all());
        $this->assertSame($court->id, Activity::where('event', 'closure-deleted')->sole()->properties['court_id']);
    }

    public function test_a_refused_action_leaves_no_entry(): void
    {
        $this->actingAs($this->lucas)->post("/admin/users/{$this->marc->id}/suspend")->assertForbidden();

        $this->assertSame(0, Activity::count());
    }

    public function test_api_tokens_are_logged_when_created_and_revoked(): void
    {
        $token = $this->postJson('/api/v1/tokens', ['email' => 'lucas.martin@example.test', 'password' => DatabaseSeeder::DEMO_PASSWORD, 'device_name' => 'tests'])->json('token');
        $this->withToken($token)->deleteJson('/api/v1/tokens/current')->assertNoContent();

        $this->assertSame(['token-created', 'token-revoked'], Activity::inLog('api')->orderBy('id')->pluck('event')->all());
        $this->assertTrue(Activity::inLog('api')->first()->causer->is($this->lucas));
        $this->assertSame(PersonalAccessToken::class, Activity::inLog('api')->first()->subject_type);
    }

    public function test_a_device_token_is_logged_with_the_manager_in_charge(): void
    {
        $this->artisan('api:partner-token', ['email' => 'marc.dumont@example.test', 'name' => 'LED terrain 1'])->assertSuccessful();

        $entry = Activity::where('event', 'device-token-created')->sole();
        $this->assertTrue($entry->causer->is($this->marc));
    }

    public function test_the_log_never_stores_the_token_itself(): void
    {
        $token = $this->postJson('/api/v1/tokens', ['email' => 'lucas.martin@example.test', 'password' => DatabaseSeeder::DEMO_PASSWORD, 'device_name' => 'tests'])->json('token');

        $this->assertStringNotContainsString(explode('|', $token)[1], json_encode(Activity::sole()->toArray()));
    }

    public function test_the_manager_reads_the_log_newest_first_with_author_and_detail(): void
    {
        $rule = PricingRule::where('name', 'Heures pleines')->firstOrFail();
        $old = $rule->price_cents;
        $this->actingAs($this->marc)->post("/admin/users/{$this->lucas->id}/suspend");
        $this->actingAs($this->marc)->patch("/admin/pricing-rules/{$rule->id}", ['price_cents' => $old + 500]);

        $this->actingAs($this->marc)->get('/admin/journal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/journal')
            ->where('entries.total', 2)
            ->where('entries.data.0.event', 'price-updated')
            ->where('entries.data.0.causer', $this->marc->name)
            ->where('entries.data.0.subject', $rule->name)
            ->where('entries.data.0.details', number_format($old / 100, 2, ',', ' ').' € → '.number_format(($old + 500) / 100, 2, ',', ' ').' €')
            ->where('entries.data.1.subject', $this->lucas->name));
    }

    public function test_the_log_can_be_filtered_and_keeps_deleted_closures_readable(): void
    {
        $court = Court::where('name', 'Terrain 2')->firstOrFail();
        $this->actingAs($this->marc)->post('/admin/closures', ['court_id' => $court->id, 'from' => '2026-12-01T08:00', 'to' => '2026-12-01T12:00', 'reason' => 'Entretien']);
        $this->actingAs($this->marc)->delete('/admin/closures/'.CourtClosure::sole()->id);
        $this->postJson('/api/v1/tokens', ['email' => 'lucas.martin@example.test', 'password' => DatabaseSeeder::DEMO_PASSWORD, 'device_name' => 'tests']);

        $this->actingAs($this->marc)->get('/admin/journal?log=staff')->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 2)
            ->where('entries.data.0.event', 'closure-deleted')
            ->where('entries.data.0.subject', 'Terrain 2')
            ->where('entries.data.0.details', '01/12/2026 08:00 → 01/12/2026 12:00'));
        $this->actingAs($this->marc)->get('/admin/journal?log=api')->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.causer', $this->lucas->name));
    }

    public function test_players_cannot_read_the_log(): void
    {
        $this->actingAs($this->lucas)->get('/admin/journal')->assertForbidden();
        $this->actingAs($this->marc)->get('/admin/journal?log=autre')->assertSessionHasErrors('log');
    }
}
