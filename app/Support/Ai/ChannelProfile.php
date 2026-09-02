<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Enums\AgentChannel;

final readonly class ChannelProfile
{
    public function __construct(
        public AgentChannel $channel,
        public ?int $maxCharacters,
        public int $segmentSize,
        public bool $supportsHtml,
        public bool $supportsSubject,
        public bool $requiresTemplateOutsideWindow,
        public bool $expectsSignature,
        public int $targetSentences,
        public string $promptAddendum = '',
    ) {}

    public static function for(AgentChannel $channel): self
    {
        return match ($channel) {
            AgentChannel::Sms => new self(
                channel: $channel,
                maxCharacters: 1_600,
                segmentSize: 160,
                supportsHtml: false,
                supportsSubject: false,
                requiresTemplateOutsideWindow: false,
                expectsSignature: false,
                targetSentences: 2,
            ),
            AgentChannel::Email => new self(
                channel: $channel,
                maxCharacters: null,
                segmentSize: 0,
                supportsHtml: true,
                supportsSubject: true,
                requiresTemplateOutsideWindow: false,
                expectsSignature: true,
                targetSentences: 8,
            ),
            AgentChannel::Whatsapp => new self(
                channel: $channel,
                maxCharacters: null,
                segmentSize: 0,
                supportsHtml: false,
                supportsSubject: false,
                requiresTemplateOutsideWindow: true,
                expectsSignature: false,
                targetSentences: 3,
            ),
            AgentChannel::Webchat => new self(
                channel: $channel,
                maxCharacters: null,
                segmentSize: 0,
                supportsHtml: false,
                supportsSubject: false,
                requiresTemplateOutsideWindow: false,
                expectsSignature: false,
                targetSentences: 4,
            ),
            AgentChannel::Internal => new self(
                channel: $channel,
                maxCharacters: null,
                segmentSize: 0,
                supportsHtml: true,
                supportsSubject: true,
                requiresTemplateOutsideWindow: false,
                expectsSignature: false,
                targetSentences: 8,
            ),
            AgentChannel::Voice => new self(
                channel: $channel,
                maxCharacters: 600,
                segmentSize: 0,
                supportsHtml: false,
                supportsSubject: false,
                requiresTemplateOutsideWindow: false,
                expectsSignature: false,
                targetSentences: 2,
                promptAddendum: 'Do not speak any figure, price, count, date, or balance aloud. Offer to text the exact quote with voice.send_quote_by_text.',
            ),
        };
    }
}
