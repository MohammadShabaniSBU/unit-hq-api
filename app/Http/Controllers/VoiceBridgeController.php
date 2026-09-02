<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Ai\VoiceBridgeAuth;
use App\Support\Ai\VoiceBridgeTurn;
use App\Support\Ai\VoiceBridgeWireFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceBridgeController extends Controller
{
    public function __invoke(Request $request, string $bridgeToken): JsonResponse
    {
        $token = app(VoiceBridgeAuth::class)->authenticate($request, $bridgeToken);
        if ($token === null) {
            return $this->unauthorized();
        }

        $inbound = VoiceBridgeWireFormat::parse($request);

        return response()->json(VoiceBridgeWireFormat::respond(
            $inbound,
            app(VoiceBridgeTurn::class)->handle($inbound, $token),
        ));
    }
}
