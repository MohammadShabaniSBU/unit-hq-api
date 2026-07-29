<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum Provider: string
{
    case Brevo = 'brevo';
    case Postmark = 'postmark';
    case Mandrill = 'mandrill';
    case Twilio = 'twilio';
    case Sinch = 'sinch';
    case Aircall = 'aircall';

    public function label(): string
    {
        return match ($this) {
            self::Brevo => 'Brevo',
            self::Postmark => 'Postmark',
            self::Mandrill => 'Mandrill',
            self::Twilio => 'Twilio',
            self::Sinch => 'Sinch',
            self::Aircall => 'Aircall',
        };
    }
}
