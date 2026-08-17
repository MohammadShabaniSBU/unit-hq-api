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
    private const SUBJECT_LOOKAHEAD_LINES = 5;

    private const SUBJECT_MAX_CHARS = 78;

    public function key(): string
    {
        return 'channel';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        $channel = $ctx->channel;
        [$subject, $body] = $this->extractSubject($draft);
        $detail = [];

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
            $subject = $this->synthesizeSubject($body);
            $detail['subject_synthesized'] = true;
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

    /**
     * @return array{0: string|null, 1: string}
     */
    private function extractSubject(string $draft): array
    {
        if (preg_match('/^Subject:\s*(.+)\s*(?:\n|$)/i', $draft, $match) === 1) {
            $subject = trim($match[1]);
            $body = ltrim((string) preg_replace('/^Subject:\s*.+\s*(?:\n|$)/i', '', $draft, 1));

            return [$subject !== '' ? $subject : null, $body];
        }

        $lines = preg_split("/\r\n|\r|\n/", $draft);
        if ($lines === false) {
            return [null, $draft];
        }

        $limit = min(self::SUBJECT_LOOKAHEAD_LINES, count($lines));
        for ($i = 0; $i < $limit; $i++) {
            if (preg_match('/^Subject:\s*(.+)\s*$/i', trim($lines[$i]), $match) !== 1) {
                continue;
            }

            $subject = trim($match[1]);
            unset($lines[$i]);

            return [$subject !== '' ? $subject : null, ltrim(implode("\n", $lines))];
        }

        return [null, $draft];
    }

    private function synthesizeSubject(string $body): string
    {
        $lines = preg_split("/\r\n|\r|\n/", $body);
        $line = '';

        foreach ($lines === false ? [] : $lines as $candidate) {
            $candidate = trim((string) preg_replace('/\s+/u', ' ', strip_tags($candidate)));
            if ($candidate !== '') {
                $line = $candidate;
                break;
            }
        }

        if ($line === '') {
            return 'Your enquiry';
        }

        if (mb_strlen($line) > self::SUBJECT_MAX_CHARS) {
            $line = rtrim(mb_substr($line, 0, self::SUBJECT_MAX_CHARS));
        }

        return $line;
    }
}
