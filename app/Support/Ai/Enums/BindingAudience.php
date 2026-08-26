<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum BindingAudience: string
{
    case KnownContacts = 'known_contacts';
    case ExistingTenants = 'existing_tenants';
    case All = 'all';
}
