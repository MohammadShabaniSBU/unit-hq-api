<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Contact;
use App\Models\Site;
use App\Support\Communications\Channel;
use App\Support\Communications\Messages\EmailAddress;
use App\Support\Communications\Messages\EmailMessage;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Results\SendResult;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\EmailSender;
use App\Support\Communications\Senders\SmsSender;
use InvalidArgumentException;

/**
 * Channel fanout for a catalogue quote. WhatsApp is a later arm — session
 * send needs an open window and template send needs an approved Meta
 * template, neither of which is in place yet.
 */
final class QuoteDelivery
{
    public function __construct(
        private readonly SmsSender $sms,
        private readonly EmailSender $email,
    ) {}

    public function supports(Channel $channel): bool
    {
        return $channel === Channel::Sms || $channel === Channel::Email;
    }

    public function send(
        Channel $channel,
        string $to,
        string $quote,
        Site $site,
        Contact $contact,
        SendContext $context,
        string $locale = 'en',
    ): SendResult {
        if (! $this->supports($channel)) {
            throw new InvalidArgumentException('Quote delivery does not support '.$channel->value.'.');
        }

        return match ($channel) {
            Channel::Sms => $this->sms->send(
                new SmsMessage(to: $to, body: $quote),
                $site,
                $contact,
                $context,
            ),
            Channel::Email => $this->email->send(
                new EmailMessage(
                    to: [new EmailAddress($to)],
                    subject: $this->emailSubject($locale),
                    html: htmlspecialchars($quote, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    text: $quote,
                ),
                $site,
                $contact,
                $context,
            ),
            default => throw new InvalidArgumentException('Quote delivery does not support '.$channel->value.'.'),
        };
    }

    private function emailSubject(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];
        $base = in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';
        $template = (string) (config("ai-handoff.quote_email_subject.{$base}")
            ?: config('ai-handoff.quote_email_subject.en', ''));

        return str_replace('{company}', DisclosureSentence::company(), $template);
    }
}
