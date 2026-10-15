<?php

declare(strict_types=1);

use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
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

Broadcast::channel('template-assist.{sessionId}', function (Employee $employee, string $sessionId): bool {
    $session = TemplateAssistSession::query()->find($sessionId);

    return $session !== null && (int) $session->employee_id === (int) $employee->id;
});

Broadcast::channel('copilot-titles.{employeeId}', function (Employee $employee, int $employeeId): bool {
    return (int) $employee->id === $employeeId;
});

Broadcast::channel('inbox', function (Employee $employee): bool {
    return Gate::forUser($employee)->allows(Permission::InboxView->value);
});

Broadcast::channel('agent-pending-actions', function (Employee $employee): bool {
    return Gate::forUser($employee)->allows(Permission::AgentActionApprove->value);
});
