<?php

declare(strict_types=1);

namespace App\Support\Ai;

use RuntimeException;

/**
 * Generates the paste-ready Vocal Bridge customer prompt from config.
 * Emits unsubstituted {company} templates — replace at dashboard paste time.
 */
final class VoiceBridgeCustomerConfig
{
    public const RELATIVE_PATH = 'docs/roadmap/sprint-28-customer-facing-voice-via-vocal-bridge-delegation/vb-customer-config.json';

    /** @return array<string, mixed> */
    public static function payload(): array
    {
        $greetings = [
            'en' => (string) config('ai-handoff.voice_greeting.en'),
            'es' => (string) config('ai-handoff.voice_greeting.es'),
            'fr' => (string) config('ai-handoff.voice_greeting.fr'),
        ];

        return [
            'greeting_locale' => 'site_default',
            'greeting_locale_note' => 'The opening line uses the site default locale (SiteLocale from the site country the number is bound to). Do not infer locale from caller ID or the first utterance. A bilingual market needs a second number or a bilingual greeting — that is a decision, not model inference. language: multi may apply after the greeting; it does not select the opening line.',
            'voice_greeting' => $greetings,
            'agent_prompt_additions' => [
                'Open every call with the exact voice_greeting line for the site default locale before anything else. Do not paraphrase. Do not skip. Do not infer locale from the caller. Replace {company} with the operator\'s registered name at paste time.',
                'Site locale en: '.$greetings['en'],
                'Site locale es: '.$greetings['es'],
                'Site locale fr: '.$greetings['fr'],
                'Never state a price, rate, discount, availability count, date, balance, invoice figure, unit number, or access code. Delegate any question needing one.',
                'Never answer a question about a specific customer\'s account. Delegate it.',
                'Never speculate about what the company offers. Delegate it.',
                'Speak the delegated answer as given.',
            ],
            'model_settings' => [
                'stt.language_source' => 'preset',
                'language' => 'multi',
                'session.max_call_duration_minutes' => 30,
            ],
        ];
    }

    public static function encoded(): string
    {
        $json = json_encode(
            self::payload(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new RuntimeException('Failed to encode Vocal Bridge customer config.');
        }

        return $json."\n";
    }

    public static function path(): string
    {
        return base_path(self::RELATIVE_PATH);
    }
}
