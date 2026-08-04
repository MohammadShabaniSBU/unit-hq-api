<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contract;
use App\Models\Employee;
use App\Support\Auth\Permission;

/**
 * Canonical thin policy — copy this shape in task 03.
 * Answers "may this person", never "is this legal right now".
 */
final class ContractPolicy extends BasePolicy
{
    public function view(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::ContractView, $contract);
    }

    public function sign(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::ContractSign, $contract);
    }

    public function vacate(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::ContractVacate, $contract);
    }

    public function transfer(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::ContractTransfer, $contract);
    }

    public function rateChange(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::ContractRateChange, $contract);
    }

    /** Autopay enable/disable/retry — PaymentRecord (no ContractManage in the enum). */
    public function recordPayment(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::PaymentRecord, $contract);
    }

    public function viewPayment(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::PaymentView, $contract);
    }

    public function issueInvoice(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::InvoiceIssue, $contract);
    }

    public function manageAccess(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::AccessManage, $contract);
    }

    public function esign(Employee $employee, Contract $contract): bool
    {
        return $this->allows($employee, Permission::EsignSend, $contract);
    }
}
