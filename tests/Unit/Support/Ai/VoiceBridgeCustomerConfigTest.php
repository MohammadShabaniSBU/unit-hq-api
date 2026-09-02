<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\VoiceBridgeCustomerConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeCustomerConfigTest extends TestCase
{
    #[Test]
    public function committed_json_matches_a_fresh_generate(): void
    {
        $committed = file_get_contents(VoiceBridgeCustomerConfig::path());

        $this->assertNotFalse($committed);
        $this->assertSame(VoiceBridgeCustomerConfig::encoded(), $committed);
    }

    #[Test]
    public function greeting_fields_match_config_templates_not_voice_greeting(): void
    {
        $payload = VoiceBridgeCustomerConfig::payload();

        foreach (['en', 'es', 'fr'] as $locale) {
            $fromConfig = (string) config("ai-handoff.voice_greeting.{$locale}");

            $this->assertSame($fromConfig, $payload['voice_greeting'][$locale]);
            $this->assertStringContainsString('{company}', $payload['voice_greeting'][$locale]);
        }
    }

    #[Test]
    public function greeting_locale_is_site_default_not_detected(): void
    {
        $payload = VoiceBridgeCustomerConfig::payload();

        $this->assertSame('site_default', $payload['greeting_locale']);
        $this->assertStringContainsString('Do not infer locale', $payload['greeting_locale_note']);
        $this->assertStringContainsString('site default locale', $payload['agent_prompt_additions'][0]);
    }
}
