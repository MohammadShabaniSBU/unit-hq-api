<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contact;
use App\Models\Employee;
use App\Support\Auth\Permission;

final class ContactPolicy extends BasePolicy
{
    public function view(Employee $employee, Contact $contact): bool
    {
        return $this->allows($employee, Permission::ContactView, $contact);
    }

    public function manage(Employee $employee, Contact $contact): bool
    {
        return $this->allows($employee, Permission::ContactManage, $contact);
    }

    public function create(Employee $employee): bool
    {
        return $employee->allowsPermission(Permission::ContactManage);
    }
}
