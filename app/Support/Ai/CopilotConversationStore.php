<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Copilot persists several sequential tool steps on one assistant row.
 * The SDK then replays the last step's provider blocks with every earlier
 * tool result, which Anthropic rejects on resume. Split completed steps
 * out so the pause turn only carries matching tool_use / tool_result ids.
 */
final class CopilotConversationStore extends DatabaseConversationStore
{
    /**
     * @param  Collection<int, array<string, mixed>>  $toolCalls
     * @param  Collection<int, array<string, mixed>>  $toolResults
     * @param  array<int, string>  $resolvedCallIds
     * @return array<int, AssistantMessage|ToolResultMessage>
     */
    #[\Override]
    protected function reconstructToolTurn(object $record, Collection $toolCalls, Collection $toolResults, array $resolvedCallIds = []): array
    {
        $callIds = $toolCalls->pluck('id')->all();

        [$priorResults, $ownResults] = $toolResults->partition(
            fn (array $toolResult) => ! in_array($toolResult['id'], $callIds, true)
        );

        $ownResultIds = $ownResults->pluck('id')->all();

        [$resolvedCalls, $pendingCalls] = $toolCalls->partition(
            fn (array $toolCall) => in_array($toolCall['id'], $ownResultIds, true)
        );

        $pausedCallIds = $this->pausedCallIds($record);

        $isPause = $pendingCalls->isNotEmpty()
            && $pendingCalls->every(fn (array $toolCall) => in_array($toolCall['id'], $pausedCallIds, true));

        $messages = [];

        if ($priorResults->isNotEmpty()) {
            $messages[] = new ToolResultMessage($priorResults->map(ToolResult::fromArray(...))->values());
        }

        $meta = (array) json_decode($record->meta ?? '[]', true);

        $providerContentBlocks = $meta['provider_content_blocks'] ?? [];

        if ($isPause && filled($providerContentBlocks)) {
            return [
                ...$messages,
                ...$this->reconstructPausedTurn(
                    $record,
                    $resolvedCalls,
                    $pendingCalls,
                    $ownResults,
                    $providerContentBlocks,
                    $meta['provider'] ?? null,
                ),
            ];
        }

        if ($resolvedCalls->isNotEmpty()) {
            $messages[] = new AssistantMessage('', $resolvedCalls->map(ToolCall::fromArray(...))->values());
            $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
        }

        $keptCalls = $pendingCalls->filter(
            fn (array $toolCall) => in_array($toolCall['id'], $pausedCallIds, true)
                || in_array($toolCall['id'], $resolvedCallIds, true)
        )->values();

        if ($keptCalls->isNotEmpty()) {
            $messages[] = new AssistantMessage($record->content, $keptCalls->map(ToolCall::fromArray(...))->values());
        } elseif (filled($record->content)) {
            $messages[] = new AssistantMessage($record->content);
        }

        return $messages;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $resolvedCalls
     * @param  Collection<int, array<string, mixed>>  $pendingCalls
     * @param  Collection<int, array<string, mixed>>  $ownResults
     * @param  array<int, array<string, mixed>>  $providerContentBlocks
     * @return array<int, AssistantMessage|ToolResultMessage>
     */
    private function reconstructPausedTurn(
        object $record,
        Collection $resolvedCalls,
        Collection $pendingCalls,
        Collection $ownResults,
        array $providerContentBlocks,
        ?string $provider,
    ): array {
        $blockToolUseIds = collect($providerContentBlocks)
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? '') === 'tool_use')
            ->map(fn (array $block): string => (string) ($block['id'] ?? ''))
            ->filter()
            ->values();

        $earlierCalls = $resolvedCalls->reject(
            fn (array $toolCall) => $blockToolUseIds->contains((string) $toolCall['id'])
        )->values();

        $earlierResults = $ownResults->reject(
            fn (array $toolResult) => $blockToolUseIds->contains((string) $toolResult['id'])
        )->values();

        $pauseResults = $ownResults->filter(
            fn (array $toolResult) => $blockToolUseIds->contains((string) $toolResult['id'])
        )->values();

        $messages = [];

        if ($earlierCalls->isNotEmpty()) {
            $messages[] = new AssistantMessage('', $earlierCalls->map(ToolCall::fromArray(...))->values());
            if ($earlierResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage($earlierResults->map(ToolResult::fromArray(...))->values());
            }
        }

        $messages[] = new AssistantMessage(
            $record->content,
            $pendingCalls->map(ToolCall::fromArray(...))->values(),
            $providerContentBlocks,
            $provider,
        );

        if ($pauseResults->isNotEmpty()) {
            $messages[] = new ToolResultMessage($pauseResults->map(ToolResult::fromArray(...))->values());
        }

        return $messages;
    }
}
