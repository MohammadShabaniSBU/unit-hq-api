<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use App\Support\Communications\UnsubscribeToken;

/**
 * HMAC public token for the preference center and RFC 8058 one-click.
 * No expiry. Version byte allows a later key rotation.
 *
 * Payload: {c, ch, a, l, m, v}.
 */
final class PreferenceToken
{
    public const VERSION = 1;

    public static function issue(
        int $contactId,
        Channel $channel,
        string $address,
        ?int $listId = null,
        ?int $messageId = null,
    ): string {
        $normalized = ContactChannelMatcher::normalize($channel, $address);
        if ($contactId <= 0 || $normalized === '') {
            throw new \InvalidArgumentException('Preference token needs a contact and an address.');
        }

        $payload = json_encode([
            'c' => $contactId,
            'ch' => $channel->value,
            'a' => $normalized,
            'l' => $listId,
            'm' => $messageId,
            'v' => self::VERSION,
        ], JSON_THROW_ON_ERROR);

        $sig = hash_hmac('sha256', $payload, self::key());

        return self::encode($payload.'|'.$sig);
    }

    public static function parse(string $token): ?PreferenceClaims
    {
        $raw = self::decode($token);
        if ($raw === null || ! str_contains($raw, '|')) {
            return null;
        }

        $split = strrpos($raw, '|');
        if ($split === false) {
            return null;
        }

        $payload = substr($raw, 0, $split);
        $sig = substr($raw, $split + 1);
        $expected = hash_hmac('sha256', $payload, self::key());
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (! is_array($data) || ($data['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $contactId = $data['c'] ?? null;
        $channel = Channel::tryFrom((string) ($data['ch'] ?? ''));
        $address = $data['a'] ?? null;
        if (! is_int($contactId) || $contactId <= 0 || $channel === null || ! is_string($address)) {
            return null;
        }

        if (! in_array($channel, [Channel::Email, Channel::Sms, Channel::Whatsapp], true)) {
            return null;
        }

        $normalized = ContactChannelMatcher::normalize($channel, $address);
        if ($normalized === '') {
            return null;
        }

        $listId = $data['l'] ?? null;
        $messageId = $data['m'] ?? null;
        if ($listId !== null && (! is_int($listId) || $listId <= 0)) {
            return null;
        }
        if ($messageId !== null && (! is_int($messageId) || $messageId <= 0)) {
            return null;
        }

        return new PreferenceClaims(
            $contactId,
            $channel,
            $normalized,
            $listId,
            $messageId,
            false,
        );
    }

    /**
     * Preference token, or a legacy UnsubscribeToken (all lists, this address).
     */
    public static function resolve(string $token): ?PreferenceClaims
    {
        $parsed = self::parse($token);
        if ($parsed !== null) {
            return $parsed;
        }

        $address = UnsubscribeToken::addressFrom($token);
        if ($address === null) {
            return null;
        }

        return new PreferenceClaims(null, Channel::Email, $address, null, null, true);
    }

    public static function pageUrl(string $token): string
    {
        return rtrim((string) config('app.panel_url'), '/').'/preferences/'.$token;
    }

    public static function unsubscribeUrl(
        int $contactId,
        Channel $channel,
        string $address,
        ?int $listId = null,
        ?int $messageId = null,
    ): string {
        return rtrim((string) config('app.url'), '/').'/api/comms/unsubscribe/'.self::issue(
            $contactId,
            $channel,
            $address,
            $listId,
            $messageId,
        );
    }

    /**
     * Template roots resolved per recipient.
     *
     * @return array{preferences_url: string, unsubscribe_url: string}
     */
    public static function templateUrls(
        int $contactId,
        Channel $channel,
        string $address,
        ?int $listId = null,
        ?int $messageId = null,
    ): array {
        $token = self::issue($contactId, $channel, $address, $listId, $messageId);

        return [
            'preferences_url' => self::pageUrl($token),
            'unsubscribe_url' => rtrim((string) config('app.url'), '/').'/api/comms/unsubscribe/'.$token,
        ];
    }

    private static function key(): string
    {
        $dedicated = config('comms.preference_token_key');
        if (is_string($dedicated) && $dedicated !== '') {
            return $dedicated;
        }

        return (string) config('app.key');
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $token): ?string
    {
        $padded = strtr($token, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($padded, true);

        return $raw === false ? null : $raw;
    }
}
