<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Ai\VoiceBridgeAuth;
use App\Support\Ai\VoiceSessionCrossTokenException;
use App\Support\Ai\VoiceSessionOpener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceBridgeSessionOpenController extends Controller
{
    public function __invoke(Request $request, string $bridgeToken): JsonResponse
    {
        $token = app(VoiceBridgeAuth::class)->authenticate($request, $bridgeToken);
        if ($token === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'bridge_session_id' => ['required', 'string'],
            'caller_number' => ['nullable', 'string'],
        ]);

        try {
            $session = app(VoiceSessionOpener::class)->open(
                $token,
                $validated['bridge_session_id'],
                $validated['caller_number'] ?? null,
            );
        } catch (VoiceSessionCrossTokenException) {
            return $this->notFound();
        }

        return response()->json([
            'id' => $session?->id,
            'bridge_session_id' => $validated['bridge_session_id'],
        ]);
    }
}
