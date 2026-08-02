<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function options(): JsonResponse
    {
        $options = Employee::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->name,
            ]);

        return $this->success($options, 'Employee options retrieved successfully.');
    }
}
