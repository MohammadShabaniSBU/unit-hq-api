<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\AgentToolInvocation;
use App\Models\AgentWritePolicy;
use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;

/**
 * Per-agent write policy, idempotency, and quotas — before AgentTool::handle().
 *
 * Replay: one invocation row, no second insert. persistToolMessage still runs
 * so the transcript shows the retry.
 */
final class AgentWritePolicyGate
{
    public function resolvePolicy(AgentContext $ctx, string $toolKey): ?AgentWritePolicy
    {
        $ctx->agent->loadMissing('writePolicies');

        return $ctx->agent->writePolicies->first(
            static fn (AgentWritePolicy $policy): bool => $policy->tool_key === $toolKey,
        );
    }

    public function denyIfOff(?AgentWritePolicy $policy): ?ToolResult
    {
        if ($policy === null || $policy->mode !== WritePolicyMode::Off) {
            return null;
        }

        return ToolResult::denied(
            ToolDeniedReason::NotAllowedForAgent,
            "Tool [{$policy->tool_key}] is switched off for this agent.",
        );
    }

    public function denyIfPropose(?AgentWritePolicy $policy): ?ToolResult
    {
        if ($policy === null || $policy->mode !== WritePolicyMode::Propose) {
            return null;
        }

        return ToolResult::denied(
            ToolDeniedReason::RequiresApproval,
            "Tool [{$policy->tool_key}] requires operator approval.",
        );
    }

    public function effectiveVerification(AgentTool $tool, ?AgentWritePolicy $policy): VerificationLevel
    {
        return $policy === null
            ? $tool->requiredVerification()
            : $policy->effectiveVerification($tool);
    }

    /**
     * @param  array<string, mixed>  $normalisedArgs
     */
    public function idempotencyKey(int $conversationId, string $toolKey, array $normalisedArgs): string
    {
        $canonical = json_encode(
            $this->sortRecursive($normalisedArgs),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $conversationId.'|'.$toolKey.'|'.($canonical !== false ? $canonical : ''));
    }

    /**
     * @param  array<string, mixed>  $normalisedArgs
     */
    public function replay(AgentContext $ctx, AgentTool $tool, array $normalisedArgs): ?ToolResult
    {
        if (! $tool->isWrite()) {
            return null;
        }

        $key = $this->idempotencyKey($ctx->conversation->id, $tool->key(), $normalisedArgs);

        $prior = AgentToolInvocation::query()
            ->where('agent_conversation_id', $ctx->conversation->id)
            ->where('idempotency_key', $key)
            ->where('status', ToolInvocationStatus::Ok)
            ->first();

        if ($prior === null) {
            return null;
        }

        $factKeys = $prior->fact_keys ?? [];

        return ToolResult::ok(
            $prior->result ?? [],
            (string) ($prior->result_summary ?? ''),
            FactBag::fromKeys($factKeys),
            replayed: true,
            idempotencyKey: $key,
            resultType: $prior->result_type,
            resultId: $prior->result_id !== null ? (int) $prior->result_id : null,
        );
    }

    public function denyIfQuotaExceeded(AgentContext $ctx, AgentTool $tool, ?AgentWritePolicy $policy): ?ToolResult
    {
        if (! $tool->isWrite() || $policy === null) {
            return null;
        }

        if ($policy->max_per_conversation !== null) {
            $used = AgentToolInvocation::query()
                ->where('agent_conversation_id', $ctx->conversation->id)
                ->where('tool_key', $tool->key())
                ->where('status', ToolInvocationStatus::Ok)
                ->count();

            if ($used >= $policy->max_per_conversation) {
                return ToolResult::denied(
                    ToolDeniedReason::QuotaExceeded,
                    "Tool [{$tool->key()}] exceeded max_per_conversation ({$policy->max_per_conversation}).",
                );
            }
        }

        if ($policy->max_per_day !== null) {
            $tz = (string) config('app.timezone');
            $start = now($tz)->startOfDay()->utc();
            $end = now($tz)->endOfDay()->utc();

            $used = AgentToolInvocation::query()
                ->where('tool_key', $tool->key())
                ->where('status', ToolInvocationStatus::Ok)
                ->whereBetween('created_at', [$start, $end])
                ->whereHas('conversation', function ($query) use ($ctx): void {
                    $query->where('ai_agent_id', $ctx->agent->id)
                        // invariant 59: demo traffic is excluded by an explicit filter at each call site
                        ->where('origin', '<>', AgentOrigin::Demo);
                })
                ->count();

            if ($used >= $policy->max_per_day) {
                return ToolResult::denied(
                    ToolDeniedReason::QuotaExceeded,
                    "Tool [{$tool->key()}] exceeded max_per_day ({$policy->max_per_day}).",
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }
}
