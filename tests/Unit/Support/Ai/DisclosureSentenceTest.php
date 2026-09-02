<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Models\Setting;
use App\Support\Ai\DisclosureSentence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DisclosureSentenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function voice_greeting_templates_keep_company_placeholder(): void
    {
        foreach (['en', 'es', 'fr'] as $locale) {
            $template = (string) config("ai-handoff.voice_greeting.{$locale}");

            $this->assertNotSame('', $template);
            $this->assertStringContainsString('{company}', $template);
            $this->assertStringNotContainsString('not recorded', mb_strtolower($template));
            $this->assertStringNotContainsString('no se graba', mb_strtolower($template));
            $this->assertStringNotContainsString('n\'est pas enregistré', mb_strtolower($template));
        }
    }

    #[Test]
    public function voice_greeting_substitutes_an_explicit_company(): void
    {
        $this->assertSame(
            'I am an automated assistant for Acme.',
            DisclosureSentence::voiceGreeting('en', 'Acme')
        );
        $this->assertSame(
            'Soy un asistente automatizado de Acme.',
            DisclosureSentence::voiceGreeting('es', 'Acme')
        );
        $this->assertSame(
            'Je suis un assistant automatisé de Acme.',
            DisclosureSentence::voiceGreeting('fr', 'Acme')
        );
    }

    #[Test]
    public function voice_greeting_unknown_locale_falls_back_to_english(): void
    {
        $this->assertSame(
            'I am an automated assistant for Acme.',
            DisclosureSentence::voiceGreeting('de', 'Acme')
        );
    }

    #[Test]
    public function voice_greeting_throws_on_empty_company_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Voice greeting requires a company name');

        DisclosureSentence::voiceGreeting('en', '');
    }

    #[Test]
    public function voice_greeting_throws_when_settings_company_is_empty(): void
    {
        Setting::setGeneral(Setting::general()->with(companyName: ''));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Voice greeting requires a company name');

        DisclosureSentence::voiceGreeting('en');
    }

    #[Test]
    public function voice_greeting_never_falls_back_to_keevaris(): void
    {
        Setting::setGeneral(Setting::general()->with(companyName: ''));

        try {
            $spoken = DisclosureSentence::voiceGreeting('en');
            $this->fail('Expected InvalidArgumentException, got: '.$spoken);
        } catch (InvalidArgumentException) {
            // Voice must throw rather than speak "Keevaris".
        }

        $this->assertSame('Keevaris', DisclosureSentence::company());
        $this->assertSame(
            'I am an automated assistant for Keevaris.',
            DisclosureSentence::for('en')
        );
    }
}
