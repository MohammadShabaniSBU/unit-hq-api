<?php

declare(strict_types=1);

namespace App\Support\Communications\Messages;

final readonly class EmailMessage
{
    /**
     * @param  list<EmailAddress>  $to
     * @param  list<EmailAddress>  $cc
     * @param  list<EmailAddress>  $bcc
     * @param  list<EmailAttachment>  $attachments
     * @param  list<string>  $tags
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $html,
        public string $text,
        public ?EmailAddress $from = null,
        public ?EmailAddress $replyTo = null,
        public array $cc = [],
        public array $bcc = [],
        public array $attachments = [],
        public array $tags = [],
        public array $headers = [],
    ) {}

    public function withSender(?EmailAddress $from, ?EmailAddress $replyTo = null): self
    {
        return new self(
            to: $this->to,
            subject: $this->subject,
            html: $this->html,
            text: $this->text,
            from: $from ?? $this->from,
            replyTo: $replyTo ?? $this->replyTo,
            cc: $this->cc,
            bcc: $this->bcc,
            attachments: $this->attachments,
            tags: $this->tags,
            headers: $this->headers,
        );
    }

    /** @param  list<string>  $tags */
    public function withTags(array $tags): self
    {
        return new self(
            to: $this->to,
            subject: $this->subject,
            html: $this->html,
            text: $this->text,
            from: $this->from,
            replyTo: $this->replyTo,
            cc: $this->cc,
            bcc: $this->bcc,
            attachments: $this->attachments,
            tags: $tags,
            headers: $this->headers,
        );
    }

    /** @param  array<string, string>  $headers */
    public function withHeaders(array $headers): self
    {
        return new self(
            to: $this->to,
            subject: $this->subject,
            html: $this->html,
            text: $this->text,
            from: $this->from,
            replyTo: $this->replyTo,
            cc: $this->cc,
            bcc: $this->bcc,
            attachments: $this->attachments,
            tags: $this->tags,
            headers: $headers,
        );
    }
}

