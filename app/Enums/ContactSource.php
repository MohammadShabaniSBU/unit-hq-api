<?php

namespace App\Enums;

enum ContactSource: string
{
    case SocialMedia = 'social_media';
    case Google = 'google';
    case Meta = 'meta';
    case Organic = 'organic';
    case Offline = 'offline';
    case WalkIns = 'walk_ins';
    case Calls = 'calls';
    case Emailing = 'emailing';
    case Referrals = 'referrals';
    case AircallPaid = 'aircall_paid';
    case EmailConversations = 'email_conversations';
    case Website = 'website';
    case WebForm = 'web_form';
    case Import = 'import';
    case Other = 'other';
}
