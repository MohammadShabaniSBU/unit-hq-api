<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\Invoice;
use App\Support\Auth\Permission;

final class InvoicePolicy extends BasePolicy
{
    public function view(Employee $employee, Invoice $invoice): bool
    {
        return $this->allows($employee, Permission::InvoiceView, $invoice);
    }

    public function issue(Employee $employee, Invoice $invoice): bool
    {
        return $this->allows($employee, Permission::InvoiceIssue, $invoice);
    }

    public function rectify(Employee $employee, Invoice $invoice): bool
    {
        return $this->allows($employee, Permission::InvoiceRectify, $invoice);
    }
}
