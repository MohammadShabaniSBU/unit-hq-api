<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $employee = Employee::query()->where('email', $validated['email'])->first();

        if ($employee === null || ! Hash::check($validated['password'], $employee->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $employee->createToken('panel')->plainTextToken;

        return $this->success([
            'token' => $token,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->role,
            ],
        ], 'Logged in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Employee) {
            $user->currentAccessToken()?->delete();
        }

        return $this->success(null, 'Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Employee) {
            return $this->unauthorized();
        }

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);
    }
}
