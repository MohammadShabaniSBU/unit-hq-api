<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

/**
 * Local pre-flight refusal (window closed, consent floor, template not approved).
 * Message is already translated via __().
 */
final class SendRefused extends CommunicationException
{
    public function __construct(
        public readonly string $reasonKey,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function windowClosed(): self
    {
        return new self(
            'whatsapp.window_closed',
            (string) __('errors.whatsapp.window_closed'),
        );
    }

    public static function consentFloor(): self
    {
        return new self(
            'whatsapp.consent_floor',
            (string) __('errors.whatsapp.consent_floor'),
        );
    }

    public static function templateNotApproved(): self
    {
        return new self(
            'whatsapp.template_not_approved',
            (string) __('errors.whatsapp.template_not_approved'),
        );
    }
}
