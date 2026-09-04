<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Ai\VoiceBridgeAuth;
use App\Support\Ai\VoiceBridgeConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceBridgeConfigController extends Controller
{
    public function __invoke(Request $request, string $bridgeToken): JsonResponse
    {
        $token = app(VoiceBridgeAuth::class)->authenticate($request, $bridgeToken);
        if ($token === null) {
            return $this->unauthorized();
        }

        return response()->json(VoiceBridgeConfig::payload($token));
    }
}
