<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Support\Auth\EmployeeInviteLink;
use App\Support\Auth\EmployeeInviteMailer;
use App\Support\Auth\OwnerFloor;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $status = $request->query('status', 'default');

        $query = Employee::query()
            ->with([
                'employeeRoles.role',
                'employeeRoles.site',
                'invitations' => static fn ($q) => $q->whereNull('accepted_at')->whereNull('revoked_at')->latest('id'),
            ]);

        if ($status === 'all') {
            // no filter
        } elseif ($status === 'deactivated') {
            $query->whereNotNull('deactivated_at');
        } elseif ($status === 'invited') {
            $query->whereNull('deactivated_at')->whereNull('password');
        } elseif ($status === 'active') {
            $query->whereNull('deactivated_at')->whereNotNull('password');
        } else {
            // default: active + invited
            $query->whereNull('deactivated_at');
        }

        $employees = $query
            ->orderByRaw('CASE WHEN deactivated_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(static fn (Employee $employee): array => self::serializeEmployee($employee))
            ->all();

        return $this->success($employees, 'Employees retrieved successfully.');
    }

    public function store(Request $request, EmployeeInviteMailer $mailer): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'grants' => ['nullable', 'array'],
            'grants.*.role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('archived_at')],
            'grants.*.site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $email = strtolower($validated['email']);
        self::assertEmailAvailable($email);

        /** @var Employee $actor */
        $actor = $request->user();

        $rawToken = null;
        $employee = DB::transaction(function () use ($validated, $email, $actor, &$rawToken): Employee {
            $employee = Employee::query()->create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $email,
                'password' => null,
            ]);

            foreach ($validated['grants'] ?? [] as $grantInput) {
                $role = Role::query()->findOrFail($grantInput['role_id']);
                $grant = new EmployeeRole([
                    'employee_id' => $employee->id,
                    'role_id' => $role->id,
                    'site_id' => $grantInput['site_id'] ?? null,
                    'granted_by' => $actor->id,
                ]);
                $grant->setRelation('role', $role);
                $grant->save();
            }

            [$invitation, $rawToken] = EmployeeInvitation::issue($employee, $actor);

            RecordsActivity::core('employee.created', $employee, [
                'employee_id' => $employee->id,
                'email' => $employee->email,
            ], $actor);

            RecordsActivity::core('employee.invited', $employee, [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'invitation_id' => $invitation->id,
            ], $actor);

            return $employee->load([
                'employeeRoles.role',
                'employeeRoles.site',
                'invitations' => static fn ($q) => $q->whereNull('accepted_at')->whereNull('revoked_at')->latest('id'),
            ]);
        });

        $inviteLink = EmployeeInviteLink::forToken((string) $rawToken);
        $emailSent = $mailer->trySend($employee, $inviteLink);

        return $this->created([
            ...self::serializeEmployee($employee),
            'invite_link' => $inviteLink,
            'email_sent' => $emailSent,
        ], 'Employee created successfully.');
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $employee->fill($validated);
        $employee->save();

        $employee->load([
            'employeeRoles.role',
            'employeeRoles.site',
            'invitations' => static fn ($q) => $q->whereNull('accepted_at')->whereNull('revoked_at')->latest('id'),
        ]);

        return $this->success(self::serializeEmployee($employee), 'Employee updated successfully.');
    }

    public function deactivate(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if ($employee->isDeactivated()) {
            throw ValidationException::withMessages([
                'employee' => [__('errors.rbac.already_deactivated')],
            ]);
        }

        /** @var Employee $actor */
        $actor = $request->user();

        DB::transaction(function () use ($employee, $actor): void {
            OwnerFloor::lockCompanyOwnerGrants();
            OwnerFloor::assertCanDeactivate($employee);

            $employee->deactivated_at = now();
            $employee->save();

            $employee->tokens()->delete();

            EmployeeInvitation::query()
                ->where('employee_id', $employee->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            RecordsActivity::core('employee.deactivated', $employee, [
                'employee_id' => $employee->id,
                'email' => $employee->email,
            ], $actor);
        });

        $employee = $employee->fresh([
            'employeeRoles.role',
            'employeeRoles.site',
            'invitations' => static fn ($q) => $q->whereNull('accepted_at')->whereNull('revoked_at')->latest('id'),
        ]);

        return $this->success(self::serializeEmployee($employee), 'Employee deactivated successfully.');
    }

    public function reactivate(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if (! $employee->isDeactivated()) {
            throw ValidationException::withMessages([
                'employee' => [__('errors.rbac.not_deactivated')],
            ]);
        }

        /** @var Employee $actor */
        $actor = $request->user();

        $employee->deactivated_at = null;
        $employee->save();

        RecordsActivity::core('employee.reactivated', $employee, [
            'employee_id' => $employee->id,
            'email' => $employee->email,
        ], $actor);

        $employee->load([
            'employeeRoles.role',
            'employeeRoles.site',
            'invitations' => static fn ($q) => $q->whereNull('accepted_at')->whereNull('revoked_at')->latest('id'),
        ]);

        return $this->success(self::serializeEmployee($employee), 'Employee reactivated successfully.');
    }

    public function storeInvitation(Request $request, Employee $employee, EmployeeInviteMailer $mailer): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if ($employee->isDeactivated()) {
            throw ValidationException::withMessages([
                'employee' => [__('errors.rbac.deactivated')],
            ]);
        }

        /** @var Employee $actor */
        $actor = $request->user();

        $rawToken = null;
        $invitation = DB::transaction(function () use ($employee, $actor, &$rawToken): EmployeeInvitation {
            EmployeeInvitation::query()
                ->where('employee_id', $employee->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            [$invitation, $rawToken] = EmployeeInvitation::issue($employee, $actor);

            RecordsActivity::core('employee.invited', $employee, [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'invitation_id' => $invitation->id,
            ], $actor);

            return $invitation;
        });

        $inviteLink = EmployeeInviteLink::forToken((string) $rawToken);
        $emailSent = $mailer->trySend($employee, $inviteLink);

        return $this->created([
            'invitation_id' => $invitation->id,
            'invite_link' => $inviteLink,
            'email_sent' => $emailSent,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ], 'Invitation sent successfully.');
    }

    public function destroyInvitation(Request $request, Employee $employee, EmployeeInvitation $invitation): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if ((int) $invitation->employee_id !== (int) $employee->id) {
            throw ValidationException::withMessages([
                'invitation' => [__('errors.rbac.invitation_mismatch')],
            ]);
        }

        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => [__('errors.invitation.unavailable')],
            ]);
        }

        /** @var Employee $actor */
        $actor = $request->user();

        $invitation->revoked_at = now();
        $invitation->save();

        RecordsActivity::core('employee.invitation.revoked', $employee, [
            'employee_id' => $employee->id,
            'email' => $employee->email,
            'invitation_id' => $invitation->id,
        ], $actor);

        return $this->success(null, 'Invitation revoked successfully.');
    }

    public function roles(Employee $employee): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $employee->load(['employeeRoles.role', 'employeeRoles.site']);

        $grants = $employee->employeeRoles
            ->map(static fn (EmployeeRole $grant): array => self::serializeGrant($grant))
            ->values()
            ->all();

        return $this->success($grants, 'Employee roles retrieved successfully.');
    }

    public function storeRole(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('archived_at')],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $role = Role::query()->findOrFail($validated['role_id']);

        $grant = new EmployeeRole([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => $validated['site_id'] ?? null,
            'granted_by' => $request->user()?->id,
        ]);
        $grant->setRelation('role', $role);
        $grant->save();

        $employee->forgetPermissionMap();
        $grant->load(['role', 'site']);

        return $this->created(self::serializeGrant($grant), 'Role granted successfully.');
    }

    public function destroyRole(Employee $employee, EmployeeRole $grant): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if ((int) $grant->employee_id !== (int) $employee->id) {
            throw ValidationException::withMessages([
                'grant' => [__('errors.rbac.grant_mismatch')],
            ]);
        }

        OwnerFloor::revoke($grant);
        $employee->forgetPermissionMap();

        return $this->success(null, 'Role grant removed successfully.');
    }

    public function options(): JsonResponse
    {
        Gate::authorize(Permission::InboxAssign->value);

        $options = Employee::query()
            ->whereNull('deactivated_at')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->name,
            ]);

        return $this->success($options, 'Employee options retrieved successfully.');
    }

    private static function assertEmailAvailable(string $email): void
    {
        $exists = Employee::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => [__('errors.rbac.email_taken')],
            ]);
        }
    }

    /**
     * @return array{
     *     id: int,
     *     first_name: string,
     *     last_name: string,
     *     name: string,
     *     email: string,
     *     status: string,
     *     last_login_at: string|null,
     *     open_invitation_id: int|null,
     *     grants: list<array<string, mixed>>
     * }
     */
    private static function serializeEmployee(Employee $employee): array
    {
        $grants = $employee->employeeRoles
            ->filter(static fn (EmployeeRole $grant): bool => $grant->role !== null && ! $grant->role->isArchived())
            ->map(static fn (EmployeeRole $grant): array => self::serializeGrant($grant))
            ->values()
            ->all();

        $openInvitation = $employee->relationLoaded('invitations')
            ? $employee->invitations->first()
            : null;

        return [
            'id' => $employee->id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'name' => $employee->name,
            'email' => $employee->email,
            'status' => $employee->status(),
            'last_login_at' => $employee->last_login_at?->toIso8601String(),
            'open_invitation_id' => $openInvitation?->id,
            'grants' => $grants,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     role_id: int,
     *     role_key: string,
     *     role_label: string,
     *     scope_level: string,
     *     site_id: int|null,
     *     site_name: string|null,
     *     is_company_wide: bool
     * }
     */
    private static function serializeGrant(EmployeeRole $grant): array
    {
        $role = $grant->role;
        $site = $grant->site;

        return [
            'id' => $grant->id,
            'role_id' => $grant->role_id,
            'role_key' => $role?->key ?? '',
            'role_label' => $role?->label ?? '',
            'scope_level' => $role?->scope_level->value ?? '',
            'site_id' => $grant->site_id !== null ? (int) $grant->site_id : null,
            'site_name' => $site instanceof Site ? $site->name : null,
            'is_company_wide' => $grant->site_id === null,
        ];
    }
}
