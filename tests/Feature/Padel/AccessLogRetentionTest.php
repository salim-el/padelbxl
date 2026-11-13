<?php

namespace Tests\Feature\Padel;

use App\Models\AccessLog;
use App\Models\Court;
use App\Services\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_scans_are_erased_after_three_months_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $court = Court::firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 03:15', Clock::ZONE));
        $old = AccessLog::create(['court_id' => $court->id, 'result' => 'refused', 'refusal_reason' => 'unknown', 'scanned_at' => now()->subMonths(3)->subMinute()]);
        $recent = AccessLog::create(['court_id' => $court->id, 'result' => 'refused', 'refusal_reason' => 'unknown', 'scanned_at' => now()->subMonths(3)->addMinute()]);

        $this->artisan('access-logs:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
