<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PipelineSource;
use App\Enums\ReservationStatus;
use App\Models\AiAgent;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\SystemEvent;
use App\Support\Leasing\ReservationHolds;
use App\Support\RecordsActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgentsRecallCommand extends Command
{
    protected $signature = 'agents:recall
                            {--agent= : Agent key to recall (required)}
                            {--since=1h : Lookback window (e.g. 30m, 1h, 2d)}
                            {--dry-run=true : Preview only. Pass --dry-run=false to commit}
                            {--offers : Recall offers (default: both when neither flag is set)}
                            {--reservations : Recall reservations}';

    protected $description = 'Expire agent-created offers and cancel agent-created reservations in a lookback window';

    public function handle(): int
    {
        $agentKey = $this->option('agent');
        if (! is_string($agentKey) || $agentKey === '') {
            $this->error('--agent is required (ai_agents.key).');

            return self::FAILURE;
        }

        $agent = AiAgent::query()->where('key', $agentKey)->first();
        if ($agent === null) {
            $this->error("Agent [{$agentKey}] not found.");

            return self::FAILURE;
        }

        try {
            $since = $this->parseSince((string) $this->option('since'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = $this->isDryRun();
        $includeOffers = (bool) $this->option('offers');
        $includeReservations = (bool) $this->option('reservations');
        if (! $includeOffers && ! $includeReservations) {
            $includeOffers = true;
            $includeReservations = true;
        }

        $offers = $includeOffers
            ? Offer::query()
                ->where('source', PipelineSource::AiAgent)
                ->where('ai_agent_id', $agent->id)
                ->where('created_at', '>=', $since)
                ->orderBy('id')
                ->get()
            : collect();

        $reservations = $includeReservations
            ? Reservation::query()
                ->with('contract')
                ->where('source', PipelineSource::AiAgent)
                ->where('ai_agent_id', $agent->id)
                ->where('created_at', '>=', $since)
                ->orderBy('id')
                ->get()
            : collect();

        $offerPlan = [];
        $skippedAccepted = collect();
        foreach ($offers as $offer) {
            if ($offer->source !== PipelineSource::AiAgent) {
                continue;
            }
            if ($offer->status === 'accepted') {
                $skippedAccepted->push($offer);
                $this->warn("SKIP offer #{$offer->id}  accepted — reservation may have become a contract");

                continue;
            }
            $offerPlan[] = $offer;
            $this->line("  expire offer #{$offer->id}  status={$offer->status}  created={$offer->created_at?->toIso8601String()}");
        }

        $reservationPlan = [];
        $skippedContracted = collect();
        foreach ($reservations as $reservation) {
            if ($reservation->source !== PipelineSource::AiAgent) {
                continue;
            }
            if ($reservation->contract !== null) {
                $skippedContracted->push($reservation);
                $this->warn("SKIP reservation #{$reservation->id}  has contract #{$reservation->contract->id}");

                continue;
            }
            $reservationPlan[] = $reservation;
            $status = $reservation->status instanceof ReservationStatus
                ? $reservation->status->value
                : (string) $reservation->status;
            $this->line("  cancel reservation #{$reservation->id}  status={$status}  created={$reservation->created_at?->toIso8601String()}");
        }

        $payload = [
            'agent_key' => $agent->key,
            'since' => $since->toIso8601String(),
            'dry_run' => $dryRun,
            'offers' => count($offerPlan),
            'reservations' => count($reservationPlan),
            'skipped_accepted_offer_ids' => $skippedAccepted->pluck('id')->all(),
            'skipped_contracted_reservation_ids' => $skippedContracted->pluck('id')->all(),
        ];

        SystemEvent::record('agents.recall.started', $agent, $payload);

        if ($dryRun) {
            $this->info('DRY RUN — no writes. Pass --dry-run=false to commit.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($offerPlan, $reservationPlan): void {
            foreach ($offerPlan as $offer) {
                $offer->update([
                    'status' => 'expired',
                    'expires_at' => now(),
                ]);
                RecordsActivity::core('offer.expired', $offer, [
                    'reason' => 'agents.recall',
                ]);
            }

            foreach ($reservationPlan as $reservation) {
                $reservation->update([
                    'status' => ReservationStatus::Cancelled,
                ]);
                ReservationHolds::release($reservation);
                RecordsActivity::core('reservation.cancelled', $reservation, [
                    'reason' => 'agents.recall',
                    'unit_id' => $reservation->unit_id,
                    'hold_expires_at' => $reservation->expires_at?->toIso8601String(),
                ]);
            }
        });

        SystemEvent::record('agents.recall.committed', $agent, [
            'agent_key' => $agent->key,
            'since' => $since->toIso8601String(),
            'dry_run' => false,
            'offers' => count($offerPlan),
            'reservations' => count($reservationPlan),
            'skipped_accepted_offer_ids' => $skippedAccepted->pluck('id')->all(),
            'skipped_contracted_reservation_ids' => $skippedContracted->pluck('id')->all(),
        ]);

        $this->info(sprintf(
            'Committed: expired %d offer(s), cancelled %d reservation(s).',
            count($offerPlan),
            count($reservationPlan),
        ));

        return self::SUCCESS;
    }

    private function isDryRun(): bool
    {
        $value = $this->option('dry-run');
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function parseSince(string $since): Carbon
    {
        if (preg_match('/^(\d+)([smhd])$/', $since, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid --since. Use a duration like 30m, 1h, or 2d.');
        }

        $n = (int) $matches[1];

        return match ($matches[2]) {
            's' => now()->subSeconds($n),
            'm' => now()->subMinutes($n),
            'h' => now()->subHours($n),
            default => now()->subDays($n),
        };
    }
}
