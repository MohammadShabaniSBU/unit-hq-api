<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\Payment;
use App\Support\Auth\Permission;

final class PaymentPolicy extends BasePolicy
{
    public function view(Employee $employee, Payment $payment): bool
    {
        return $this->allows($employee, Permission::PaymentView, $payment);
    }

    public function record(Employee $employee, Payment $payment): bool
    {
        return $this->allows($employee, Permission::PaymentRecord, $payment);
    }

    public function refund(Employee $employee, Payment $payment): bool
    {
        return $this->allows($employee, Permission::PaymentRefund, $payment);
    }
}
