<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee;
use Laravel\Sanctum\Sanctum;

/**
 * Authenticate a manager employee for Feature HTTP tests.
 * Call from setUp after parent::setUp().
 */
trait AuthenticatesAsEmployee
{
    protected function authenticateAsEmployee(): Employee
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        return $employee;
    }
}
