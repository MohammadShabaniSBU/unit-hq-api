<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\Auth\EmployeeAuthPayload;
use App\Support\RecordsActivity;
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

        $email = strtolower($validated['email']);
        $employee = Employee::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        $passwordOk = $employee !== null
            && $employee->password !== null
            && ! $employee->isDeactivated()
            && Hash::check($validated['password'], $employee->password);

        if (! $passwordOk) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $employee->last_login_at = now();
        $employee->save();

        $token = $employee->createToken('panel')->plainTextToken;

        return $this->success([
            'token' => $token,
            'employee' => EmployeeAuthPayload::for($employee),
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

        return $this->success(EmployeeAuthPayload::for($user));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Employee) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user->fill($validated);
        $user->save();

        return $this->success(EmployeeAuthPayload::for($user), 'Profile updated successfully.');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Employee) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($user->password === null || ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('errors.rbac.current_password_incorrect')],
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        RecordsActivity::core('employee.password.changed', $user, [
            'employee_id' => $user->id,
            'email' => $user->email,
        ], $user);

        return $this->success(null, 'Password updated successfully.');
    }
}
