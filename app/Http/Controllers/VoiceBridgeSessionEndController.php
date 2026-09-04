<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SystemEvent;
use App\Models\VoiceSession;
use App\Support\Ai\VoiceBridgeAuth;
use App\Support\Ai\VoiceSessionCrossTokenException;
use App\Support\Ai\VoiceSessionOpener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceBridgeSessionEndController extends Controller
{
    public function __invoke(Request $request, string $bridgeToken, string $bridgeSessionId): JsonResponse
    {
        $token = app(VoiceBridgeAuth::class)->authenticate($request, $bridgeToken);
        if ($token === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'end_reason' => ['required', 'string'],
        ]);

        $session = VoiceSession::query()
            ->where('bridge_session_id', $bridgeSessionId)
            ->where('voice_bridge_token_id', $token->id)
            ->first();

        if ($session !== null) {
            $this->endIfOpen($session, $validated['end_reason']);

            return response()->json([
                'id' => $session->id,
                'bridge_session_id' => $session->bridge_session_id,
            ]);
        }

        try {
            $session = app(VoiceSessionOpener::class)->open(
                $token,
                $bridgeSessionId,
                null,
                skipAudience: true,
            );
        } catch (VoiceSessionCrossTokenException) {
            return $this->notFound();
        }

        if ($session === null) {
            SystemEvent::record('voice_session.end_without_open', $token, [
                'bridge_session_id' => $bridgeSessionId,
                'end_reason' => $validated['end_reason'],
                'persisted' => false,
            ]);

            return response()->json([
                'id' => null,
                'bridge_session_id' => $bridgeSessionId,
            ]);
        }

        if ($session->wasRecentlyCreated) {
            $session->ended_at = $session->started_at;
            $session->end_reason = $validated['end_reason'];
            $session->save();

            SystemEvent::record('voice_session.end_without_open', $session, [
                'bridge_session_id' => $bridgeSessionId,
                'end_reason' => $validated['end_reason'],
            ]);
        } else {
            $this->endIfOpen($session, $validated['end_reason']);
        }

        return response()->json([
            'id' => $session->id,
            'bridge_session_id' => $session->bridge_session_id,
        ]);
    }

    private function endIfOpen(VoiceSession $session, string $endReason): void
    {
        if ($session->ended_at !== null) {
            return;
        }

        $session->ended_at = now();
        $session->end_reason = $endReason;
        $session->save();
    }
}
