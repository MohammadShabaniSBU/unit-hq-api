<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Marketing\ConsentRuleException;
use App\Support\Marketing\PreferenceCenter;
use App\Support\Marketing\PreferenceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public List-Unsubscribe floor.
 * GET redirects to the preference page and writes nothing.
 * POST is RFC 8058 one-click.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse|RedirectResponse|Response
    {
        $claims = PreferenceToken::resolve($token);

        if ($claims === null) {
            return $this->error('Invalid unsubscribe token.', statusCode: 404);
        }

        if (! $request->isMethod('POST')) {
            return redirect()->away(PreferenceToken::pageUrl($token));
        }

        try {
            PreferenceCenter::oneClick($claims, PreferenceCenter::evidence($request, $claims));
        } catch (ConsentRuleException) {
            return $this->error('Invalid unsubscribe token.', statusCode: 404);
        }

        return response('OK', 200);
    }
}
