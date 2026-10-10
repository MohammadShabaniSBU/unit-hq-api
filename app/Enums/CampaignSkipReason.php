<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignSkipReason: string
{
    case NoConsent = 'no_consent';
    case Suppressed = 'suppressed';
    case FrequencyCapped = 'frequency_capped';
    case ExcludedDelinquent = 'excluded_delinquent';
    case ExcludedPlaybook = 'excluded_playbook';
    case NoAddress = 'no_address';
    case OutOfScope = 'out_of_scope';
    case ChannelNotConfigured = 'channel_not_configured';
    case TemplateNotResolvable = 'template_not_resolvable';
    case Cancelled = 'cancelled';
}
