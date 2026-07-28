<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\CredentialStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SiteStripeSettingResource;
use App\Models\Site;
use App\Models\SiteStripeSetting;
use App\Support\Auth\SiteAccess;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialField;
use App\Support\Credentials\CredentialMasker;
use App\Support\Http\PublicUrlGuard;
use App\Support\Http\PublicUrlUnreachableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

/**
 * Per-site Stripe credentials (05-billing-ledger.md — direct charges,
 * site is the merchant of record, no Connect, no mode column).
 */
class SiteStripeSettingController extends Controller
{
    private const ENTITY = 'site_stripe_setting';

    public function show(Site $site): JsonResponse
    {
        $setting = $site->stripeSetting ?? new SiteStripeSetting([
            'site_id' => $site->id,
            'status' => CredentialStatus::Disconnected,
        ]);

        return $this->success(
            SiteStripeSettingResource::make($setting),
            'Stripe settings retrieved successfully.'
        );
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        abort_unless(SiteAccess::canManageSite($request->user(), $site), 403);

        $validated = $request->validate([
            'publishable_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string'],
        ]);

        $setting = $site->stripeSetting ?? new SiteStripeSetting(['site_id' => $site->id]);
        $isRotate = $setting->exists && CredentialMasker::readSafely($setting, 'secret_key') !== null;

        if (array_key_exists('publishable_key', $validated)) {
            $setting->publishable_key = $validated['publishable_key'];
        }

        if (CredentialField::isBlank($validated['secret_key'] ?? null)) {
            // Blank submitted field = unchanged, never wipe.
            if (! $setting->exists) {
                throw ValidationException::withMessages([
                    'secret_key' => ['A secret key is required to connect Stripe.'],
                ]);
            }

            $setting->save();

            return $this->success(
                SiteStripeSettingResource::make($setting->fresh()),
                'Stripe settings saved successfully.'
            );
        }

        $submittedSecret = CredentialField::normalize($validated['secret_key']);

        try {
            (new StripeClient($submittedSecret))->balance->retrieve();
            $status = CredentialStatus::Connected;
            $lastError = null;
        } catch (ApiErrorException $e) {
            $status = CredentialStatus::Error;
            $lastError = $e->getMessage();
        }

        $setting->secret_key = $submittedSecret;
        $setting->status = $status;
        $setting->verified_at = $status === CredentialStatus::Connected ? now() : null;
        $setting->last_error = $lastError;
        $setting->save();

        if ($isRotate) {
            CredentialAudit::rotated(self::ENTITY, $site, $site->id, 'stripe', $submittedSecret, $status->value);
        } else {
            CredentialAudit::created(self::ENTITY, $site, $site->id, 'stripe', $submittedSecret, $status->value);
        }

        return $this->success(
            SiteStripeSettingResource::make($setting->fresh()),
            'Stripe settings saved successfully.'
        );
    }

    public function createWebhook(Request $request, Site $site): JsonResponse
    {
        abort_unless(SiteAccess::canManageSite($request->user(), $site), 403);

        $setting = $site->stripeSetting;
        $secretKey = $setting !== null ? CredentialMasker::readSafely($setting, 'secret_key') : null;

        if ($setting === null || $secretKey === null) {
            throw ValidationException::withMessages([
                'secret_key' => ['Connect a Stripe secret key before creating a webhook.'],
            ]);
        }

        try {
            PublicUrlGuard::assertPublic();
        } catch (PublicUrlUnreachableException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $setting->webhook_route_token ??= Str::random(40);
        $url = PublicUrlGuard::webhookUrl("api/webhooks/stripe/{$setting->webhook_route_token}");

        try {
            /** @var WebhookEndpoint $endpoint */
            $endpoint = (new StripeClient($secretKey))->webhookEndpoints->create([
                'url' => $url,
                'enabled_events' => [
                    'payment_intent.succeeded',
                    'payment_intent.payment_failed',
                    'charge.refunded',
                ],
            ]);
        } catch (ApiErrorException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $setting->webhook_endpoint_id = $endpoint->id;
        $setting->webhook_secret = $endpoint->secret;
        $setting->save();

        return $this->success(
            SiteStripeSettingResource::make($setting->fresh()),
            'Stripe webhook created successfully.'
        );
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        abort_unless(SiteAccess::canManageSite($request->user(), $site), 403);

        $setting = $site->stripeSetting;

        if ($setting === null) {
            return $this->noContent('Stripe settings already removed.');
        }

        $secretKey = CredentialMasker::readSafely($setting, 'secret_key');

        if ($setting->webhook_endpoint_id !== null && $secretKey !== null) {
            try {
                (new StripeClient($secretKey))->webhookEndpoints->delete($setting->webhook_endpoint_id);
            } catch (ApiErrorException) {
                // Best-effort: still remove the local record so the operator
                // isn't stuck with a credential they can't rotate.
            }
        }

        $setting->delete();

        CredentialAudit::removed(self::ENTITY, $site, $site->id, 'stripe', $secretKey);

        return $this->noContent('Stripe settings removed successfully.');
    }
}
