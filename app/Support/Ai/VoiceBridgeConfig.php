<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\VoiceBridgeToken;
use App\Support\Communications\SiteLocale;

/**
 * Runtime config for keevaris-voice. Sibling of VoiceBridgeCustomerConfig —
 * same greeting / prompt-addition source, different shape (no A2A envelope).
 */
final class VoiceBridgeConfig
{
    /**
     * @return array{
     *     company_name: string,
     *     locale: string,
     *     greeting: string,
     *     filler: string,
     *     prompt_additions: mixed,
     *     transfer: array{main_line_number: string|null, voicemail_number: string|null},
     *     max_call_duration_minutes: int
     * }
     */
    public static function payload(VoiceBridgeToken $token): array
    {
        $site = $token->site()->with('legalEntity', 'country')->first();
        $companyName = $site?->legalEntity?->trading_name
            ?? $site?->legalEntity?->legal_name
            ?? (string) config('app.name');
        $locale = SiteLocale::for($site);

        // Inline substitution, not DisclosureSentence::voiceGreeting() — that
        // method is explicitly "not a runtime path" (artifact-generation only).
        $template = (string) (config("ai-handoff.voice_greeting.{$locale}") ?: config('ai-handoff.voice_greeting.en'));
        $greeting = str_replace('{company}', $companyName, $template);

        return [
            'company_name' => $companyName,
            'locale' => $locale,
            'greeting' => $greeting,
            'filler' => (string) config('ai-handoff.voice_filler'),
            'prompt_additions' => config('ai-handoff.voice_prompt_additions'),
            'transfer' => [
                'main_line_number' => $token->main_line_number,
                'voicemail_number' => $token->voicemail_number,
            ],
            'max_call_duration_minutes' => (int) config('ai-handoff.session.max_call_duration_minutes', 30),
        ];
    }
}
