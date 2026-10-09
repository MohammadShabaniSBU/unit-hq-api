<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Communications\Channel;
use App\Support\Communications\SuppressionWriter;
use App\Support\Communications\UnsubscribeToken;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentWriter;
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

        // GET stays consent-neutral. Link scanners hit GET; task 03 makes that
        // path non-destructive for suppression too. One-click is POST.
        if ($request->isMethod('POST')) {
            ConsentWriter::withdrawAllListsForAddress(Channel::Email, $address, ConsentSource::OneClick);

            return response('OK', 200);
        }

        return $this->success(['address' => $address], 'Unsubscribed.');
    }
}
