<?php

declare(strict_types=1);

use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copy sales/support agent_write_policies onto concierge with strictest-wins.
 *
 * Strictest-wins exists so a merge can never raise autonomy. sales.create_reservation
 * must land no weaker than propose / 1 / 20 because S27-02's binding repoint leaves
 * existing agent_pending_actions on the legacy agent on the assumption those caps survive.
 *
 * Does not archive or rename the legacy rows — that is seeder-only. Fresh databases
 * with no sales/support row are a no-op; AiAgentSeeder owns that path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $legacy = AiAgent::query()->whereIn('key', ['sales', 'support'])->get()->keyBy('id');
            if ($legacy->isEmpty()) {
                return;
            }

            $concierge = AiAgent::query()->firstOrCreate(
                ['key' => 'concierge'],
                [
                    'name' => 'Customer Agent',
                    'description' => null,
                    'model' => (string) config('agents.default_model'),
                    'is_active' => true,
                ],
            );

            $policies = AgentWritePolicy::query()
                ->whereIn('ai_agent_id', $legacy->keys())
                ->orderBy('id')
                ->get()
                ->groupBy('tool_key');

            foreach ($policies as $toolKey => $rows) {
                $merged = $this->merge($rows);
                $existing = AgentWritePolicy::query()
                    ->where('ai_agent_id', $concierge->id)
                    ->where('tool_key', $toolKey)
                    ->first();

                if ($existing !== null && $this->identical($existing, $merged)) {
                    continue;
                }

                $policy = AgentWritePolicy::query()->updateOrCreate(
                    [
                        'ai_agent_id' => $concierge->id,
                        'tool_key' => $toolKey,
                    ],
                    [
                        'mode' => $merged['mode'],
                        'max_per_conversation' => $merged['max_per_conversation'],
                        'max_per_day' => $merged['max_per_day'],
                        'min_verification' => $merged['min_verification'],
                        'updated_by_employee_id' => null,
                    ],
                );

                RecordsActivity::core('ai.write_policy.merged', $policy, [
                    'tool_key' => $toolKey,
                    'from' => $rows->map(fn (AgentWritePolicy $row): array => [
                        'agent_key' => $legacy->get($row->ai_agent_id)?->key,
                        'mode' => $row->mode->value,
                        'max_per_conversation' => $row->max_per_conversation,
                        'max_per_day' => $row->max_per_day,
                        'min_verification' => $row->min_verification?->value,
                    ])->values()->all(),
                    'to' => [
                        'mode' => $merged['mode']->value,
                        'max_per_conversation' => $merged['max_per_conversation'],
                        'max_per_day' => $merged['max_per_day'],
                        'min_verification' => $merged['min_verification']?->value,
                    ],
                ], anonymous: true);
            }
        });
    }

    public function down(): void
    {
        // Original policy values are recoverable only from the activity log.
        // A data-merge rollback is not something down() should attempt.
    }

    /**
     * @param  Collection<int, AgentWritePolicy>  $rows
     * @return array{mode: WritePolicyMode, max_per_conversation: int|null, max_per_day: int|null, min_verification: VerificationLevel|null}
     */
    private function merge(Collection $rows): array
    {
        $mode = null;
        $maxPerConversation = null;
        $maxPerDay = null;
        $minVerification = null;
        $seenCapConversation = false;
        $seenCapDay = false;

        foreach ($rows as $row) {
            if ($mode === null || $row->mode->rank() < $mode->rank()) {
                $mode = $row->mode;
            }

            $maxPerConversation = $seenCapConversation
                ? $this->stricterCap($maxPerConversation, $row->max_per_conversation)
                : $row->max_per_conversation;
            $seenCapConversation = true;

            $maxPerDay = $seenCapDay
                ? $this->stricterCap($maxPerDay, $row->max_per_day)
                : $row->max_per_day;
            $seenCapDay = true;

            if (
                $row->min_verification !== null
                && ($minVerification === null || $row->min_verification->rank() > $minVerification->rank())
            ) {
                $minVerification = $row->min_verification;
            }
        }

        if ($mode === null) {
            throw new RuntimeException('Cannot merge agent_write_policies: empty group.');
        }

        return [
            'mode' => $mode,
            'max_per_conversation' => $maxPerConversation,
            'max_per_day' => $maxPerDay,
            'min_verification' => $minVerification,
        ];
    }

    /**
     * @param  array{mode: WritePolicyMode, max_per_conversation: int|null, max_per_day: int|null, min_verification: VerificationLevel|null}  $merged
     */
    private function identical(AgentWritePolicy $existing, array $merged): bool
    {
        return $existing->mode === $merged['mode']
            && $existing->max_per_conversation === $merged['max_per_conversation']
            && $existing->max_per_day === $merged['max_per_day']
            && $existing->min_verification === $merged['min_verification'];
    }

    private function stricterCap(?int $left, ?int $right): ?int
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return min($left, $right);
    }
};
