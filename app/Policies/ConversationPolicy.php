<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CopilotConversation;
use App\Models\Employee;
use Laravel\Ai\Models\Conversation;

final class ConversationPolicy
{
    public function view(Employee $employee, Conversation|CopilotConversation $conversation): bool
    {
        return $this->owns($employee, $conversation);
    }

    public function delete(Employee $employee, Conversation|CopilotConversation $conversation): bool
    {
        return $this->owns($employee, $conversation);
    }

    private function owns(Employee $employee, Conversation|CopilotConversation $conversation): bool
    {
        return $conversation->participant_type === 'employee'
            && (int) $conversation->participant_id === (int) $employee->id;
    }
}
