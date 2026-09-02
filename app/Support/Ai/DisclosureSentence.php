<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Setting;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentMessageRole;
use InvalidArgumentException;

final class DisclosureSentence
{
    public static function company(): string
    {
        $name = Setting::general()->companyName;

        return $name !== '' ? $name : 'Keevaris';
    }

    public static function for(string $locale, ?string $company = null): string
    {
        $base = self::localeKey($locale);
        $template = (string) (config("ai-handoff.disclosure.{$base}") ?: config('ai-handoff.disclosure.en', ''));
        if ($template === '') {
            return '';
        }

        return str_replace('{company}', $company ?? self::company(), $template);
    }

    /**
     * Spoken Art. 50 greeting for the Vocal Bridge foreground agent.
     *
     * No production caller by design — this is the source for the generated
     * `vb-customer-config.json` artifact, not a runtime path.
     *
     * Separate from {@see for()}: voice will take a recording clause later,
     * and the spoken line has a different legal sign-off owner than chat.
     * Do not delegate to for(); editing disclosure.* must not change what
     * is spoken on a call.
     *
     * Never falls back to "Keevaris". An empty company name throws.
     */
    public static function voiceGreeting(string $locale, ?string $company = null): string
    {
        $base = self::localeKey($locale);
        $template = (string) (config("ai-handoff.voice_greeting.{$base}") ?: config('ai-handoff.voice_greeting.en', ''));
        if ($template === '') {
            return '';
        }

        $name = $company ?? Setting::general()->companyName;
        if ($name === '') {
            throw new InvalidArgumentException(
                'Voice greeting requires a company name; the Keevaris fallback is chat-only.'
            );
        }

        return str_replace('{company}', $name, $template);
    }

    public static function isPresentIn(string $draft, string $locale): bool
    {
        $phrase = self::normalize(self::for($locale));
        if ($phrase === '') {
            return false;
        }

        return mb_stripos(self::normalize($draft), $phrase) !== false;
    }

    public static function instruction(string $locale): string
    {
        $sentence = self::for($locale);
        if ($sentence === '') {
            return '';
        }

        return 'This is the first message of the conversation. Open with this exact sentence, then answer: "'.$sentence.'"';
    }

    public static function isFirstCustomerTurn(AgentContext $ctx): bool
    {
        if ($ctx->principal->audience !== AgentAudience::Customer) {
            return false;
        }

        $prior = $ctx->conversation->messages()
            ->where('role', AgentMessageRole::Assistant->value)
            ->whereNull('blocked_by')
            ->whereNull('tool_calls')
            ->count();

        return $prior === 0;
    }

    private static function localeKey(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];

        return in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';
    }

    private static function normalize(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($collapsed);
    }
}
