<?php

declare(strict_types=1);

use App\Models\Employee;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('contact.{contactId}', function (Employee $employee, int $contactId): bool {
    // Any authenticated employee for now — tighten with permissions later.
    return true;
});
