<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VoiceSession;
use App\Models\VoiceSessionTurn;
use App\Models\VoiceTranscriptSegment;
use App\Support\Ai\VoiceBridgeAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceBridgeTranscriptController extends Controller
{
    public function __invoke(Request $request, string $bridgeToken, string $bridgeSessionId): JsonResponse
    {
        $token = app(VoiceBridgeAuth::class)->authenticate($request, $bridgeToken);
        if ($token === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'segments' => ['required', 'array'],
            'segments.*.sequence' => ['required', 'integer'],
            'segments.*.role' => ['required', 'string', 'in:caller,agent'],
            'segments.*.text' => ['required', 'string'],
            'segments.*.source' => ['required', 'string', 'in:stt,fast_model,delegated'],
            'segments.*.occurred_at' => ['required', 'date'],
            'segments.*.turn_id' => ['nullable', 'string'],
            'segments.*.round_trip_ms' => ['nullable', 'integer', 'min:0'],
            'segments.*.filler_spoken' => ['nullable', 'boolean'],
        ]);

        $session = VoiceSession::query()
            ->where('bridge_session_id', $bridgeSessionId)
            ->where('voice_bridge_token_id', $token->id)
            ->first();

        if ($session === null) {
            return $this->notFound();
        }

        $stored = 0;
        foreach ($validated['segments'] as $segment) {
            $turnId = $segment['turn_id'] ?? null;
            $turn = is_string($turnId) && $turnId !== ''
                ? VoiceSessionTurn::findByTurnId($session, $turnId)
                : null;

            if ($turn !== null) {
                $telemetry = [];
                if (array_key_exists('round_trip_ms', $segment) && $segment['round_trip_ms'] !== null) {
                    $telemetry['round_trip_ms'] = $segment['round_trip_ms'];
                }
                if (array_key_exists('filler_spoken', $segment) && $segment['filler_spoken'] !== null) {
                    $telemetry['filler_spoken'] = $segment['filler_spoken'];
                }
                if ($telemetry !== []) {
                    $turn->update($telemetry);
                }
            }

            VoiceTranscriptSegment::query()->firstOrCreate(
                [
                    'voice_session_id' => $session->id,
                    'sequence' => $segment['sequence'],
                ],
                [
                    'role' => $segment['role'],
                    'text' => $segment['text'],
                    'source' => $segment['source'],
                    'voice_session_turn_id' => $turn?->id,
                    'occurred_at' => $segment['occurred_at'],
                ],
            );
            $stored++;
        }

        return response()->json(['stored' => $stored]);
    }
}
