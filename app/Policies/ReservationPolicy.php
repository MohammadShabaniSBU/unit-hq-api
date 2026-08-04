<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\Reservation;
use App\Support\Auth\Permission;

final class ReservationPolicy extends BasePolicy
{
    public function view(Employee $employee, Reservation $reservation): bool
    {
        return $this->allows($employee, Permission::ReservationManage, $reservation);
    }

    public function manage(Employee $employee, Reservation $reservation): bool
    {
        return $this->allows($employee, Permission::ReservationManage, $reservation);
    }
}
