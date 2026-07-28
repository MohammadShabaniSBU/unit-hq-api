<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Supported outbound communication providers.
 *
 * Brevo: transactional email (and SMS via the same API).
 * Snich: SMS/WhatsApp provider (adapter stub — no live SDK integration yet).
 */
enum CommunicationProviderType: string
{
    case Brevo = 'brevo';
    case Snich = 'snich';
}
