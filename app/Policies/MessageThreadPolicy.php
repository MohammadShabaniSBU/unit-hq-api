<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\MessageThread;
use App\Support\Auth\Permission;

final class MessageThreadPolicy extends BasePolicy
{
    public function view(Employee $employee, MessageThread $thread): bool
    {
        return $this->allows($employee, Permission::InboxView, $thread);
    }

    public function send(Employee $employee, MessageThread $thread): bool
    {
        return $this->allows($employee, Permission::InboxSend, $thread);
    }

    public function assign(Employee $employee, MessageThread $thread): bool
    {
        return $this->allows($employee, Permission::InboxAssign, $thread);
    }
}
