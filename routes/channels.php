<?php

declare(strict_types=1);

use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('contact.{contactId}', function (Employee $employee, int $contactId): bool {
    // Any authenticated employee for now — tighten with permissions later.
    return true;
});

Broadcast::channel('copilot.{conversationId}', function (Employee $employee, string $conversationId): bool {
    $conversation = CopilotConversation::query()->find($conversationId);

    return $conversation !== null
        && Gate::forUser($employee)->allows('view', $conversation);
});

Broadcast::channel('inbox', function (Employee $employee): bool {
    return Gate::forUser($employee)->allows(Permission::InboxView->value);
});

Broadcast::channel('agent-pending-actions', function (Employee $employee): bool {
    return Gate::forUser($employee)->allows(Permission::AgentActionApprove->value);
});
