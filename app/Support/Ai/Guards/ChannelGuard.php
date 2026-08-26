<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Tools\FactBag;
use App\Support\Communications\Gsm7Transliterator;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\WhatsAppWindow;

final class ChannelGuard implements OutboundGuard
{
    private const SUBJECT_LOOKAHEAD_LINES = 10;

    private const SUBJECT_MAX_CHARS = 78;

    public function key(): string
    {
        return 'channel';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        $channel = $ctx->channel;
        [$subject, $body, $preambleStripped] = $this->extractSubject($draft);
        $detail = [];
        if ($preambleStripped) {
            $detail['preamble_stripped'] = true;
        }

        if (! $channel->supportsHtml && $body !== strip_tags($body)) {
            $body = strip_tags($body);
            $detail['html_stripped'] = true;
        }

        $verdict = 'pass';

        if ($channel->channel === AgentChannel::Sms) {
            $transliterated = Gsm7Transliterator::apply($body);
            if ($transliterated['changed']) {
                $body = $transliterated['body'];
                $detail['gsm7_transliterated'] = true;
            }

            $sms = new SmsMessage('guard', $body);
            $segments = $sms->segmentCount();
            $maxSegments = (int) config('agents.channel.sms.max_segments', 5);
            $warnSegments = (int) config('agents.channel.sms.warn_segments', 3);
            $detail['segments'] = $segments;
            $detail['encoding'] = $sms->encoding();
            $detail['max_segments'] = $maxSegments;

            if ($segments > $maxSegments) {
                $detail['reason'] = 'sms_too_long';

                return GuardrailVerdict::retry(
                    "Rewrite this reply in at most {$maxSegments} GSM-7 SMS segments. Keep the same facts.",
                    'channel',
                    HandoffReason::Error,
                    $detail,
                    [['guard' => $this->key(), 'verdict' => 'deny', 'reason' => 'sms_too_long', 'detail' => $detail]],
                );
            }

            if ($segments >= $warnSegments) {
                $verdict = 'warn';
            }
        }

        if ($channel->supportsSubject && ($subject === null || $subject === '')) {
            $subject = $this->synthesizeSubject($body, $ctx);
            $detail['subject_synthesized'] = true;
        }

        if ($channel->channel === AgentChannel::Whatsapp) {
            $conversation = $ctx->conversation;
            $conversation->loadMissing('messageThread');
            $thread = $conversation->messageThread;

            if ($thread !== null && $channel->requiresTemplateOutsideWindow && ! WhatsAppWindow::isOpen($thread)) {
                $detail['reason'] = 'whatsapp_window_closed';
                $detail['outside_window_mode'] = 'template';

                return GuardrailVerdict::block(
                    $this->key(),
                    HandoffReason::ChannelConstraint,
                    $detail,
                    [['guard' => $this->key(), 'verdict' => 'deny', 'reason' => 'whatsapp_window_closed', 'detail' => $detail]],
                );
            }

            if ($thread === null) {
                $detail['advisory'] = true;
            }
            $detail['outside_window_mode'] = 'template';
            $detail['inside_window_mode'] = 'session';
            $detail['requires_template_outside_window'] = $channel->requiresTemplateOutsideWindow;
        }

        $mutated = $body !== $draft ? $body : null;
        $event = ['guard' => $this->key(), 'verdict' => $verdict];
        if ($detail !== []) {
            $event['detail'] = $detail;
        }

        return GuardrailVerdict::pass($mutated, [$event], $subject);
    }

    /**
     * @return array{0: string|null, 1: string, 2: bool}
     */
    private function extractSubject(string $draft): array
    {
        if (preg_match('/^Subject:\s*(.+)\s*(?:\n|$)/i', $draft, $match) === 1) {
            $subject = trim($match[1]);
            $body = ltrim((string) preg_replace('/^Subject:\s*.+\s*(?:\n|$)/i', '', $draft, 1));

            return [$subject !== '' ? $subject : null, $body, false];
        }

        // DisclosureGuard prepends on the same line (`phrase.' '.$trimmed`), so a first
        // customer-turn email looks like `I am an automated assistant. Subject: Availability\n…`.
        if (preg_match('/^([^\r\n]+)\h+Subject:\s*([^\r\n]+)(?:\r?\n([\s\S]*))?$/i', $draft, $match) === 1) {
            $preamble = trim($match[1]);
            $subject = trim($match[2]);
            $rest = ltrim($match[3] ?? '');
            $body = $rest === '' ? $preamble : ($preamble === '' ? $rest : $preamble.' '.$rest);

            return [$subject !== '' ? $subject : null, $body, $preamble !== ''];
        }

        $lines = preg_split("/\r\n|\r|\n/", $draft);
        if ($lines === false) {
            return [null, $draft, false];
        }

        $limit = min(self::SUBJECT_LOOKAHEAD_LINES, count($lines));
        for ($i = 0; $i < $limit; $i++) {
            if (preg_match('/^Subject:\s*(.+)\s*$/i', trim($lines[$i]), $match) !== 1) {
                continue;
            }

            $subject = trim($match[1]);
            $body = ltrim(implode("\n", array_slice($lines, $i + 1)));

            return [$subject !== '' ? $subject : null, $body, $i > 0];
        }

        return [null, $draft, false];
    }

    private function synthesizeSubject(string $body, AgentContext $ctx): string
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

        $line = $this->stripDisclosure($line, $ctx);

        if ($line === '') {
            return 'Your enquiry';
        }

        if (mb_strlen($line) > self::SUBJECT_MAX_CHARS) {
            $line = rtrim(mb_substr($line, 0, self::SUBJECT_MAX_CHARS));
        }

        return $line;
    }

    private function stripDisclosure(string $line, AgentContext $ctx): string
    {
        $phrase = DisclosureGuard::phraseFor($ctx->conversation->locale ?? $ctx->principal->locale);
        if ($phrase === '' || $line === '') {
            return $line;
        }

        if (mb_strtolower($line) === mb_strtolower($phrase)) {
            return '';
        }

        $prefix = $phrase.' ';
        if (mb_stripos($line, $prefix) === 0) {
            return ltrim(mb_substr($line, mb_strlen($prefix)));
        }

        $suffix = ' '.$phrase;
        $suffixLen = mb_strlen($suffix);
        if (mb_strlen($line) >= $suffixLen
            && mb_strtolower(mb_substr($line, -$suffixLen)) === mb_strtolower($suffix)
        ) {
            return rtrim(mb_substr($line, 0, mb_strlen($line) - $suffixLen));
        }

        return $line;
    }
}
