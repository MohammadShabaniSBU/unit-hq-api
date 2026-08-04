<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\Offer;
use App\Support\Auth\Permission;

final class OfferPolicy extends BasePolicy
{
    public function view(Employee $employee, Offer $offer): bool
    {
        return $this->allows($employee, Permission::OfferManage, $offer);
    }

    public function manage(Employee $employee, Offer $offer): bool
    {
        return $this->allows($employee, Permission::OfferManage, $offer);
    }

    public function send(Employee $employee, Offer $offer): bool
    {
        return $this->allows($employee, Permission::OfferSend, $offer);
    }
}
