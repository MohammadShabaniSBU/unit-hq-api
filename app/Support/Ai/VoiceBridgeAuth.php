<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use Illuminate\Http\Request;

/**
 * Path token + shared-secret header. Accepts current or previous secret so
 * rotation does not require the dashboard and the database to flip together.
 */
final class VoiceBridgeAuth
{
    public function authenticate(Request $request, string $pathToken): ?VoiceBridgeToken
    {
        $token = VoiceBridgeToken::query()
            ->where('token', $pathToken)
            ->whereNull('revoked_at')
            ->first();

        if ($token === null) {
            return null;
        }

        $headerName = (string) config('agents.voice.bridge_secret_header', 'X-Voice-Bridge-Secret');
        $provided = (string) $request->header($headerName);

        $current = (string) $token->secret;
        $previous = (string) ($token->secret_previous ?? '');

        $currentOk = hash_equals($current, $provided);
        $previousOk = hash_equals($previous, $provided);

        if (! ($currentOk || ($previous !== '' && $previousOk))) {
            SystemEvent::record('ai.voice.bridge_auth_failed', $token, [
                'reason' => 'bad_secret',
            ]);

            return null;
        }

        return $token;
    }
}
