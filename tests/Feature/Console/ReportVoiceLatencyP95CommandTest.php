<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\VoiceSessionTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportVoiceLatencyP95CommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function prints_p95_from_known_round_trip_values(): void
    {
        foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000,
            1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900, 2000] as $ms) {
            VoiceSessionTurn::factory()->create([
                'round_trip_ms' => $ms,
            ]);
        }

        VoiceSessionTurn::factory()->create([
            'round_trip_ms' => null,
        ]);

        $this->artisan('agents:report-voice-latency-p95')
            ->expectsOutputToContain('p95 round_trip_ms: 1900 (n=20)')
            ->assertSuccessful();
    }

    #[Test]
    public function since_excludes_older_turns(): void
    {
        VoiceSessionTurn::factory()->create([
            'round_trip_ms' => 100,
            'created_at' => now()->subDays(10),
        ]);
        VoiceSessionTurn::factory()->create([
            'round_trip_ms' => 500,
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('agents:report-voice-latency-p95', [
            '--since' => now()->subDays(2)->toDateString(),
        ])
            ->expectsOutputToContain('p95 round_trip_ms: 500 (n=1')
            ->assertSuccessful();
    }

    #[Test]
    public function empty_range_prints_no_data_message(): void
    {
        $this->artisan('agents:report-voice-latency-p95')
            ->expectsOutputToContain('No voice_session_turns with round_trip_ms in range.')
            ->assertSuccessful();
    }

    #[Test]
    public function invalid_since_fails(): void
    {
        $this->artisan('agents:report-voice-latency-p95', ['--since' => 'not-a-date'])
            ->expectsOutputToContain('Invalid --since value: not-a-date')
            ->assertFailed();
    }
}
