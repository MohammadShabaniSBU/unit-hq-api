<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AutomationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $emailSubject,
        public readonly string $bodyHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject !== '' ? $this->emailSubject : '(no subject)',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->bodyHtml !== '' ? $this->bodyHtml : '<p></p>',
        );
    }
}
