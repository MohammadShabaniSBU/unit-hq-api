<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Tools\FactBag;
use App\Support\Communications\Messages\SmsMessage;

final class ChannelGuard implements OutboundGuard
{
    public function key(): string
    {
        return 'channel';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        $channel = $ctx->channel;
        $body = $draft;
        $subject = null;
        $detail = [];

        if (preg_match('/^Subject:\s*(.+)\s*(?:\n|$)/i', $body, $match) === 1) {
            $subject = trim($match[1]);
            $body = ltrim((string) preg_replace('/^Subject:\s*.+\s*(?:\n|$)/i', '', $body, 1));
        }

        if (! $channel->supportsHtml && $body !== strip_tags($body)) {
            $body = strip_tags($body);
            $detail['html_stripped'] = true;
        }

        if ($channel->channel === AgentChannel::Sms) {
            $sms = new SmsMessage('guard', $body);
            $detail['segments'] = $sms->segmentCount();
            $detail['encoding'] = $sms->encoding();

            $max = $channel->maxCharacters;
            if ($max !== null && mb_strlen($body) > $max) {
                return GuardrailVerdict::retry(
                    "Rewrite this reply in at most {$max} characters. Keep the same facts.",
                    'channel',
                    HandoffReason::Error,
                    $detail,
                    [['guard' => $this->key(), 'verdict' => 'retry', 'detail' => $detail]],
                );
            }
        }

        if ($channel->supportsSubject && ($subject === null || $subject === '')) {
            return GuardrailVerdict::retry(
                'Start the draft with a line of the form `Subject: …` followed by the body.',
                'channel',
                HandoffReason::Error,
                $detail,
                [['guard' => $this->key(), 'verdict' => 'retry', 'detail' => array_merge($detail, ['missing_subject' => true])]],
            );
        }

        if ($channel->channel === AgentChannel::Whatsapp) {
            $detail['advisory'] = true;
            $detail['outside_window_mode'] = 'template';
            $detail['inside_window_mode'] = 'session';
            $detail['requires_template_outside_window'] = $channel->requiresTemplateOutsideWindow;
        }

        $mutated = $body !== $draft ? $body : null;
        $event = ['guard' => $this->key(), 'verdict' => 'pass'];
        if ($detail !== []) {
            $event['detail'] = $detail;
        }

        return GuardrailVerdict::pass($mutated, [$event], $subject);
    }
}
