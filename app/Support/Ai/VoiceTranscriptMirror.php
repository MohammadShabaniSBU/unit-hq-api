<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\VoiceSession;
use App\Support\Ai\Enums\AgentMessageRole;
use Illuminate\Support\Facades\DB;

/**
 * Appends caller / front-desk turns the Vocal Bridge sent as
 * `context_segments` onto the agent conversation. Watermarked by
 * `voice_sessions.mirrored_transcript_sequence` so a repeated batch is free.
 */
final class VoiceTranscriptMirror
{
    public const FRONT_DESK_PREFIX = '[front desk] ';

    /**
     * @param  list<array{sequence: int, role: string, text: string, source: string, occurred_at?: string|null}>  $segments
     * @return list<string> caller texts actually inserted, in sequence order
     */
    public function apply(VoiceSession $session, AgentConversation $conversation, array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        return DB::transaction(function () use ($session, $conversation, $segments): array {
            $session->refresh();
            $watermark = (int) ($session->mirrored_transcript_sequence ?? 0);
            $pending = $this->pendingAboveWatermark($segments, $watermark);
            if ($pending === []) {
                return [];
            }

            $callerTexts = [];
            $maxSequence = $watermark;

            foreach ($pending as $segment) {
                $role = $segment['role'];
                $source = $segment['source'];
                $text = $segment['text'];

                if ($role === 'caller' && $source === 'stt') {
                    $this->append($conversation, AgentMessageRole::User, $text, 'stt');
                    $callerTexts[] = $text;
                } elseif ($role === 'agent' && $source === 'fast_model') {
                    $this->append(
                        $conversation,
                        AgentMessageRole::Assistant,
                        self::FRONT_DESK_PREFIX.$text,
                        'fast_model',
                    );
                } else {
                    continue;
                }

                $maxSequence = max($maxSequence, $segment['sequence']);
            }

            if ($maxSequence > $watermark) {
                $session->mirrored_transcript_sequence = $maxSequence;
                $session->save();
            }

            return $callerTexts;
        });
    }

    /**
     * @param  list<array{sequence: int, role: string, text: string, source: string, occurred_at?: string|null}>  $segments
     * @return list<array{sequence: int, role: string, text: string, source: string, occurred_at?: string|null}>
     */
    private function pendingAboveWatermark(array $segments, int $watermark): array
    {
        $pending = array_values(array_filter(
            $segments,
            fn (array $segment): bool => $segment['sequence'] > $watermark,
        ));
        usort($pending, fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

        return $pending;
    }

    private function append(
        AgentConversation $conversation,
        AgentMessageRole $role,
        string $content,
        string $voiceSource,
    ): void {
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $conversation->id,
            'sequence' => ((int) $conversation->messages()->max('sequence')) + 1,
            'role' => $role,
            'content' => $content,
            'voice_source' => $voiceSource,
            'fact_keys' => null,
        ]);
    }
}
