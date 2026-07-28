<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

/**
 * Public endpoint: the Stripe publishable key is not a secret and is needed
 * client-side (Stripe Elements) on payment pages that are not behind auth.
 */
class SiteStripePublicKeyController extends Controller
{
    public function show(Site $site): JsonResponse
    {
        if ($site->isArchived()) {
            return $this->notFound('Site not found.');
        }

        return $this->success([
            'site_id' => $site->id,
            'publishable_key' => $site->stripeSetting?->publishable_key,
        ], 'Stripe public key retrieved successfully.');
    }
}
