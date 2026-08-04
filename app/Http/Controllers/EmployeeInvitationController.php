<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmployeeInvitation;
use App\Support\Auth\EmployeeAuthPayload;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeInvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = EmployeeInvitation::query()
            ->where('token_hash', EmployeeInvitation::hashToken($token))
            ->with('employee')
            ->first();

        if ($invitation === null) {
            return $this->error(__('errors.invitation.unavailable'), [], 410);
        }

        if ($invitation->isSpent() || $invitation->isExpired()) {
            return $this->error(__('errors.invitation.unavailable'), [
                'reason' => $invitation->isExpired() ? 'expired' : 'spent',
            ], 410);
        }

        $employee = $invitation->employee;
        if ($employee === null || $employee->isDeactivated()) {
            return $this->error(__('errors.invitation.unavailable'), [], 410);
        }

        return $this->success([
            'email' => $employee->email,
            'first_name' => $employee->first_name,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ], 'Invitation retrieved successfully.');
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:12'],
        ]);

        $result = DB::transaction(function () use ($token, $validated) {
            $invitation = EmployeeInvitation::query()
                ->where('token_hash', EmployeeInvitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->isSpent() || $invitation->isExpired()) {
                return null;
            }

            $employee = $invitation->employee()->lockForUpdate()->first();
            if ($employee === null || $employee->isDeactivated()) {
                return null;
            }

            $employee->password = $validated['password'];
            $employee->last_login_at = now();
            $employee->save();

            $invitation->accepted_at = now();
            $invitation->save();

            RecordsActivity::core('employee.invitation.accepted', $employee, [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'invitation_id' => $invitation->id,
            ], $employee);

            $plainTextToken = $employee->createToken('panel')->plainTextToken;

            return [
                'token' => $plainTextToken,
                'employee' => EmployeeAuthPayload::for($employee),
            ];
        });

        if ($result === null) {
            return $this->error(__('errors.invitation.unavailable'), [], 410);
        }

        return $this->success($result, 'Invitation accepted.');
    }
}
