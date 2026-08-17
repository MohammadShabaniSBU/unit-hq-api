<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Enums\AgentChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelProfileTest extends TestCase
{
    #[Test]
    public function every_channel_case_resolves(): void
    {
        foreach (AgentChannel::cases() as $channel) {
            $profile = ChannelProfile::for($channel);

            $this->assertSame($channel, $profile->channel);
        }
    }

    #[Test]
    public function sms_has_character_and_segment_caps(): void
    {
        $sms = ChannelProfile::for(AgentChannel::Sms);

        $this->assertSame(1_600, $sms->maxCharacters);
        $this->assertSame(160, $sms->segmentSize);
        $this->assertFalse($sms->supportsHtml);
        $this->assertSame(2, $sms->targetSentences);
    }

    #[Test]
    public function email_allows_html_subject_and_signature(): void
    {
        $email = ChannelProfile::for(AgentChannel::Email);

        $this->assertNull($email->maxCharacters);
        $this->assertTrue($email->supportsHtml);
        $this->assertTrue($email->supportsSubject);
        $this->assertTrue($email->expectsSignature);
        $this->assertSame(8, $email->targetSentences);
    }

    #[Test]
    public function whatsapp_requires_template_outside_window(): void
    {
        $whatsapp = ChannelProfile::for(AgentChannel::Whatsapp);

        $this->assertTrue($whatsapp->requiresTemplateOutsideWindow);
        $this->assertSame(3, $whatsapp->targetSentences);
    }

    #[Test]
    public function voice_is_short_spoken_plain_text(): void
    {
        $voice = ChannelProfile::for(AgentChannel::Voice);

        $this->assertSame(600, $voice->maxCharacters);
        $this->assertSame(0, $voice->segmentSize);
        $this->assertFalse($voice->supportsHtml);
        $this->assertFalse($voice->supportsSubject);
        $this->assertFalse($voice->requiresTemplateOutsideWindow);
        $this->assertFalse($voice->expectsSignature);
        $this->assertSame(2, $voice->targetSentences);
    }
}
