<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VoiceSessionTurn;
use App\Models\VoiceTranscriptSegment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VoiceSessionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->datetime($this->started_at),
            'ended_at' => $this->datetime($this->ended_at),
            'caller_number' => $this->caller_number,
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', function () {
                if ($this->contact === null) {
                    return null;
                }

                return [
                    'id' => $this->contact->id,
                    'first_name' => $this->contact->first_name,
                    'last_name' => $this->contact->last_name,
                ];
            }),
            'site_id' => $this->site_id,
            'site' => $this->whenLoaded('site', fn () => [
                'id' => $this->site->id,
                'name' => $this->site->name,
            ]),
            'delegated_span_seconds' => $this->delegatedSpanSeconds(),
            'transfer_requested' => $this->transferRequested(),
            'verification_level' => $this->whenLoaded('conversation', function () {
                $level = $this->conversation->verification_level;

                return $level instanceof \BackedEnum ? $level->value : $level;
            }),
            'conversation' => AgentConversationResource::make($this->whenLoaded('conversation')),
            'turns' => $this->whenLoaded('turns', fn () => $this->turns->map(
                fn (VoiceSessionTurn $turn): array => [
                    'id' => $turn->id,
                    'turn_id' => $turn->turn_id,
                    'answer_text' => $turn->answer_text,
                    'transfer' => $turn->transfer,
                    'destination' => $turn->destination,
                    'latency_ms' => $turn->latency_ms,
                    'round_trip_ms' => $turn->round_trip_ms,
                    'filler_spoken' => $turn->filler_spoken,
                    'handoff_reason' => $turn->handoff_reason,
                    'agent_conversation_message_id' => $turn->agent_conversation_message_id,
                ],
            )->values()->all()),
            'transcript_segments' => $this->whenLoaded('voiceTranscriptSegments', fn () => $this->voiceTranscriptSegments->map(
                fn (VoiceTranscriptSegment $segment): array => [
                    'id' => $segment->id,
                    'sequence' => $segment->sequence,
                    'role' => $segment->role,
                    'text' => $segment->text,
                    'source' => $segment->source,
                    'occurred_at' => $this->datetime($segment->occurred_at),
                    'voice_session_turn_id' => $segment->voice_session_turn_id,
                ],
            )->values()->all()),
        ];
    }

    private function delegatedSpanSeconds(): ?int
    {
        $latest = $this->resource->getAttribute('turns_max_created_at');
        if ($latest === null && $this->relationLoaded('turns')) {
            $latest = $this->turns->max('created_at');
        }
        if ($latest === null || $this->started_at === null) {
            return null;
        }

        $seconds = Carbon::parse($this->started_at)->diffInSeconds(Carbon::parse($latest), false);

        return max(0, (int) $seconds);
    }

    private function transferRequested(): bool
    {
        $attributes = $this->resource->getAttributes();
        if (array_key_exists('transfer_requested', $attributes)) {
            return (bool) $this->resource->getAttribute('transfer_requested');
        }
        if ($this->relationLoaded('turns')) {
            return $this->turns->contains(fn (VoiceSessionTurn $turn): bool => $turn->transfer);
        }

        return false;
    }
}
