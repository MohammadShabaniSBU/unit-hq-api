<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\MarketingTouch;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ContactMarketingTouchController extends Controller
{
    private const LIMIT = 50;

    public function index(Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value, $contact);

        $touches = MarketingTouch::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return $this->success(
            $touches->map(fn (MarketingTouch $touch): array => [
                'occurred_at' => $touch->occurred_at->toDateTimeString(),
                'platform' => $touch->platform?->value,
                'utm_campaign' => $touch->utm_campaign,
                'landing_path' => $this->landingPath($touch->landing_url),
            ])->values()->all(),
            'Marketing touches retrieved successfully.',
        );
    }

    private function landingPath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $path;
    }
}
