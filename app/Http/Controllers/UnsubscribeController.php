<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Communications\SuppressionWriter;
use App\Support\Communications\UnsubscribeToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public List-Unsubscribe floor — writes marketing-scope suppression. Idempotent.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse|Response
    {
        $address = UnsubscribeToken::addressFrom($token);

        if ($address === null) {
            return $this->error('Invalid unsubscribe token.', statusCode: 404);
        }

        SuppressionWriter::fromUnsubscribe($address);

        // RFC 8058 one-click expects 2xx; keep body minimal.
        if ($request->isMethod('POST')) {
            return response('OK', 200);
        }

        return $this->success(['address' => $address], 'Unsubscribed.');
    }
}
