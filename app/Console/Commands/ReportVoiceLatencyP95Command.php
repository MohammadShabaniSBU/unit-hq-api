<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VoiceSessionTurn;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class ReportVoiceLatencyP95Command extends Command
{
    protected $signature = 'agents:report-voice-latency-p95 {--since=}';

    protected $description = 'Print p95 of voice_session_turns.round_trip_ms over an optional date range';

    public function handle(): int
    {
        $since = $this->option('since');
        $sinceAt = null;

        if (is_string($since) && $since !== '') {
            try {
                $sinceAt = Carbon::parse($since);
            } catch (Throwable) {
                $this->error("Invalid --since value: {$since}");

                return self::FAILURE;
            }
        }

        $query = VoiceSessionTurn::query()
            ->whereNotNull('round_trip_ms')
            ->orderBy('round_trip_ms');

        if ($sinceAt !== null) {
            $query->where('created_at', '>=', $sinceAt);
        }

        $values = $query->pluck('round_trip_ms');
        $count = $values->count();

        if ($count === 0) {
            $this->info('No voice_session_turns with round_trip_ms in range.');

            return self::SUCCESS;
        }

        $index = (int) ceil(0.95 * $count) - 1;
        $p95 = (int) $values[$index];

        $suffix = $sinceAt !== null ? ', since='.$sinceAt->toDateString() : '';
        $this->info("p95 round_trip_ms: {$p95} (n={$count}{$suffix})");

        return self::SUCCESS;
    }
}
