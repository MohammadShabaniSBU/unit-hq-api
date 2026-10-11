<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use App\Models\Contact;
use App\Models\MarketingTouch;
use App\Models\SignupForm;
use App\Support\Advertising\Contracts\CapturesClickIds;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * Writes one marketing touch from a form submission. No row unless the
 * payload's consent snapshot has ad_storage.
 */
final class TouchRecorder
{
    private const MAX_ENCODED = 2048;

    /** @var list<string> */
    private const URL_CLICK_PARAMS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'oai_ref'];

    /**
     * Writes the touch when ad_storage is on, and returns the consent snapshot
     * even when the touch is skipped.
     *
     * @return array{ad_storage: bool, ad_user_data: bool, ad_personalization: bool}|null
     */
    public static function fromEncoded(SignupForm $form, Contact $contact, mixed $encoded): ?array
    {
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_ENCODED) {
            return null;
        }

        $json = self::base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $consent = is_array($data['consent'] ?? null) ? $data['consent'] : [];
        unset($data['consent']);

        self::fromSubmission($form, $contact, $data, $consent);

        return self::consentSnapshot($consent);
    }

    /**
     * @param  array<string, mixed>  $touch
     * @param  array<string, mixed>  $consent
     */
    public static function fromSubmission(SignupForm $form, Contact $contact, array $touch, array $consent): void
    {
        $snapshot = self::consentSnapshot($consent);
        if ($snapshot['ad_storage'] !== true) {
            return;
        }

        $clickIds = self::clickIds($touch);
        $utmSource = self::cap(self::stringOrNull($touch['utm_source'] ?? null), 128);
        $visitorKey = self::visitorKey($touch['visitor_key'] ?? null);
        $landing = self::landingUrl(self::stringOrNull($touch['landing_url'] ?? null));

        MarketingTouch::query()->create([
            'contact_id' => $contact->id,
            'visitor_key' => $visitorKey,
            'occurred_at' => self::occurredAt($touch['occurred_at'] ?? null),
            'site_id' => $form->site_id,
            'platform' => self::platform($clickIds, $utmSource),
            'utm_source' => $utmSource,
            'utm_medium' => self::cap(self::stringOrNull($touch['utm_medium'] ?? null), 128),
            'utm_campaign' => self::cap(self::stringOrNull($touch['utm_campaign'] ?? null), 255),
            'utm_term' => self::cap(self::stringOrNull($touch['utm_term'] ?? null), 255),
            'utm_content' => self::cap(self::stringOrNull($touch['utm_content'] ?? null), 255),
            'click_ids' => $clickIds,
            'landing_url' => $landing,
            'referrer_host' => self::referrerHost(self::stringOrNull($touch['referrer'] ?? $touch['referrer_host'] ?? null)),
            'signup_form_id' => $form->id,
            'consent_snapshot' => $snapshot,
        ]);

        if ($visitorKey === null) {
            return;
        }

        MarketingTouch::query()
            ->where('visitor_key', $visitorKey)
            ->whereNull('contact_id')
            ->orderBy('id')
            ->each(function (MarketingTouch $prior) use ($contact): void {
                $prior->contact_id = $contact->id;
                $prior->save();
            });
    }

    /**
     * @param  array<string, mixed>  $consent
     * @return array{ad_storage: bool, ad_user_data: bool, ad_personalization: bool}
     */
    private static function consentSnapshot(array $consent): array
    {
        return [
            'ad_storage' => self::flag($consent['ad_storage'] ?? false),
            'ad_user_data' => self::flag($consent['ad_user_data'] ?? false),
            'ad_personalization' => self::flag($consent['ad_personalization'] ?? false),
        ];
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'granted';
    }

    /**
     * @param  array<string, mixed>  $touch
     * @return array<string, string>
     */
    private static function clickIds(array $touch): array
    {
        $ids = [];
        foreach (self::knownClickIdKeys() as $key) {
            $value = self::cap(self::stringOrNull($touch[$key] ?? null), 255);
            if ($value !== null) {
                $ids[$key] = $value;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function knownClickIdKeys(): array
    {
        $keys = [];
        foreach (self::platformKeys() as $pair) {
            foreach ($pair[1] as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Google click ids, then Meta, then the reserved OpenAI key.
     *
     * @return list<array{0: AdPlatform, 1: list<string>}>
     */
    private static function platformKeys(): array
    {
        $pairs = [];
        $registry = new AdPlatformRegistry;
        foreach ([AdPlatform::GoogleAds, AdPlatform::Meta] as $platform) {
            if (! $registry->capturesClickIds($platform)) {
                continue;
            }

            $adapter = $registry->make($platform, []);
            if (! $adapter instanceof CapturesClickIds) {
                continue;
            }

            $pairs[] = [$platform, $adapter->clickIdKeys()];
        }

        $pairs[] = [AdPlatform::OpenAi, ['oai_ref']];

        return $pairs;
    }

    /**
     * @param  array<string, string>  $clickIds
     */
    private static function platform(array $clickIds, ?string $utmSource): ?AdPlatform
    {
        foreach (self::platformKeys() as [$platform, $keys]) {
            foreach ($keys as $key) {
                if (($clickIds[$key] ?? '') !== '') {
                    return $platform;
                }
            }
        }

        return match (strtolower(trim((string) $utmSource))) {
            'google' => AdPlatform::GoogleAds,
            'facebook', 'instagram' => AdPlatform::Meta,
            'chatgpt' => AdPlatform::OpenAi,
            default => null,
        };
    }

    private static function landingUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return self::cap($url, 1000);
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (self::URL_CLICK_PARAMS as $key) {
                unset($query[$key]);
            }
        }

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'].'://';
        }
        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (isset($parts['pass'])) {
                $rebuilt .= ':'.$parts['pass'];
            }
            $rebuilt .= '@';
        }
        $rebuilt .= $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt === '' ? null : self::cap($rebuilt, 1000);
    }

    private static function referrerHost(?string $referrer): ?string
    {
        if ($referrer === null) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            if (str_contains($referrer, '/') || str_contains($referrer, ' ')) {
                return null;
            }
            $host = $referrer;
        }

        return self::cap(strtolower($host), 255);
    }

    private static function occurredAt(mixed $value): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $floor = $now->subDays(90);

        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $n = (int) $value;
                $at = $n > 10_000_000_000
                    ? CarbonImmutable::createFromTimestampMs($n)
                    : CarbonImmutable::createFromTimestamp($n);
            } elseif (is_string($value) && trim($value) !== '') {
                $at = CarbonImmutable::parse($value);
            } else {
                $at = $now;
            }
        } catch (\Throwable) {
            $at = $now;
        }

        if ($at->greaterThan($now)) {
            return $now;
        }

        if ($at->lessThan($floor)) {
            return $floor;
        }

        return $at;
    }

    private static function visitorKey(mixed $value): ?string
    {
        $key = self::stringOrNull($value);
        if ($key === null || preg_match('/^[A-Za-z0-9]{32}$/', $key) !== 1) {
            return null;
        }

        return $key;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function cap(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
