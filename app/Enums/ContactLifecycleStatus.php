<?php

namespace App\Enums;

enum ContactLifecycleStatus: string
{
    case Prospect = 'prospect';
    case Lead = 'lead';
    case Opportunity = 'opportunity';
    case Tenant = 'tenant';
    case PastTenant = 'past_tenant';
    case Lost = 'lost';
}
