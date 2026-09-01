<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\Eval\CassetteStore;
use App\Support\Ai\Eval\EvalHarness;
use Illuminate\Console\Command;

class AgentReplayCommand extends Command
{
    protected $signature = 'agent:replay
                            {--agent= : Limit to one agent key (concierge; sales|support are archived)}
                            {--filter= : Substring match on fixture id or tags}
                            {--live : Hit the real model (dev only)}
                            {--record : Overwrite cassette responses (requires --live)}
                            {--seal : Rewrite prompt_hash and schema_hash only; never hits the network}
                            {--json : Machine-readable output}
                            {--path= : Fixture root (default tests/Fixtures/agents)}';

    protected $description = 'Replay customer-facing agent fixtures against cassettes or a live model';

    public function handle(EvalHarness $harness): int
    {
        $live = (bool) $this->option('live');
        $record = (bool) $this->option('record');
        $seal = (bool) $this->option('seal');
        $json = (bool) $this->option('json');

        if ($record && ! $live) {
            $this->error('--record requires --live.');

            return self::FAILURE;
        }

        if ($seal && ($live || $record)) {
            $this->error('--seal cannot be combined with --live or --record.');

            return self::FAILURE;
        }

        if ($live) {
            if ($this->laravel->environment('production')) {
                $this->error('--live is refused in production.');

                return self::FAILURE;
            }
            if (! filter_var(config('agents.demo_enabled'), FILTER_VALIDATE_BOOLEAN)) {
                $this->error('--live is refused while agents.demo_enabled is false.');

                return self::FAILURE;
            }
        }

        $root = (string) ($this->option('path') ?: CassetteStore::defaultRoot());
        $agent = $this->option('agent');
        $filter = $this->option('filter');

        $result = $harness->run(
            $root,
            is_string($agent) && $agent !== '' ? $agent : null,
            is_string($filter) && $filter !== '' ? $filter : null,
            $live,
            $record,
            $seal,
        );

        if ($seal) {
            if ($json) {
                $this->line(json_encode(['sealed' => $result['sealed']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info("Sealed {$result['sealed']} cassette(s). Review the hash-only diff, then commit.");
            }

            return self::SUCCESS;
        }

        $report = $result['report'];
        if ($json) {
            $this->line(json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->output->write($report->toHuman());
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }
}
