<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Support\Auth\Permission;
use Database\Factories\EmployeeFactory;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Assigns demo-world employee grants and verifies coherence at seed tail.
 */
final class DemoRbacGrants
{
    /**
     * @param  Collection<int, Site>  $sites
     */
    public static function assign(Collection $sites): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $byCode = $sites->keyBy('code');
        $madrid = $byCode->get('MAD-01') ?? $sites->firstOrFail();
        $norte = $byCode->get('MAD-02') ?? $sites->skip(1)->first() ?? $madrid;
        $sur = $byCode->get('MAD-03') ?? $sites->skip(2)->first() ?? $madrid;

        // Owner already created as manager@example.com by StageSeeder.
        $owner = Employee::query()->where('email', 'manager@example.com')->firstOrFail();
        EmployeeFactory::grantCompanyRole($owner, 'owner');

        self::ensureEmployee('ops@example.com', 'Ops Manager', 'operations_manager', null);

        foreach ($sites as $site) {
            $slug = strtolower((string) $site->code);
            self::ensureEmployee(
                "sm-{$slug}@example.com",
                'Site Manager '.$site->code,
                'site_manager',
                $site,
            );
        }

        self::ensureEmployee('agent-mad@example.com', 'Ana López', 'leasing_agent', $madrid);
        self::ensureEmployee('agent-norte@example.com', 'Bea Martín', 'leasing_agent', $norte);
        self::ensureEmployee('agent-sur@example.com', 'Luis Ortega', 'leasing_agent', $sur);

        self::ensureEmployee('accountant@example.com', 'Carmen Contable', 'accountant', null);
        self::ensureEmployee('readonly@example.com', 'Rita Lectura', 'read_only', null);
    }

    /**
     * @return list<array{email: string, name: string, role: string, site: string}>
     */
    public static function grantTableRows(): array
    {
        $rows = [];

        $grants = EmployeeRole::query()
            ->with(['employee', 'role', 'site'])
            ->orderBy('employee_id')
            ->orderBy('role_id')
            ->get();

        foreach ($grants as $grant) {
            $employee = $grant->employee;
            $role = $grant->role;
            if ($employee === null || $role === null) {
                continue;
            }
            $rows[] = [
                'email' => (string) $employee->email,
                'name' => (string) $employee->name,
                'role' => (string) $role->key,
                'site' => $grant->site_id === null
                    ? 'company-wide'
                    : (string) ($grant->site?->code ?? $grant->site_id),
            ];
        }

        return $rows;
    }

    /**
     * Fail loudly if demo RBAC coherence is broken.
     */
    public static function verifyOrFail(): void
    {
        $employees = Employee::query()->with('employeeRoles.role')->get();
        $withoutGrant = $employees->filter(
            static fn (Employee $e): bool => $e->employeeRoles->isEmpty(),
        );

        if ($withoutGrant->isNotEmpty()) {
            $emails = $withoutGrant->pluck('email')->implode(', ');
            throw new RuntimeException(
                "demo:seed RBAC check failed — employees without grants: {$emails}",
            );
        }

        $ownerCount = EmployeeRole::query()
            ->whereNull('site_id')
            ->whereHas('role', static fn ($q) => $q->where('key', 'owner'))
            ->count();

        if ($ownerCount < 1) {
            throw new RuntimeException(
                'demo:seed RBAC check failed — no company-wide owner grant exists',
            );
        }

        $owner = Employee::query()
            ->whereHas('employeeRoles', static function ($q): void {
                $q->whereNull('site_id')
                    ->whereHas('role', static fn ($r) => $r->where('key', 'owner'));
            })
            ->firstOrFail();

        $ownerIds = Contract::query()
            ->visibleTo($owner, Permission::ContractView)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $agents = Employee::query()
            ->whereHas('employeeRoles.role', static fn ($q) => $q->where('key', 'leasing_agent'))
            ->get();

        foreach ($agents as $agent) {
            $agent->forgetPermissionMap();
            $agentIds = Contract::query()
                ->visibleTo($agent, Permission::ContractView)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($agentIds === []) {
                throw new RuntimeException(
                    "demo:seed RBAC check failed — leasing_agent {$agent->email} sees zero contracts",
                );
            }

            $extra = array_values(array_diff($agentIds, $ownerIds));
            if ($extra !== []) {
                throw new RuntimeException(
                    "demo:seed RBAC check failed — leasing_agent {$agent->email} sees contracts outside owner set: "
                    .implode(', ', $extra),
                );
            }

            if (count($agentIds) >= count($ownerIds)) {
                throw new RuntimeException(
                    "demo:seed RBAC check failed — leasing_agent {$agent->email} visible set is not a strict subset of owner "
                    .'(agent='.count($agentIds).', owner='.count($ownerIds).')',
                );
            }
        }
    }

    private static function ensureEmployee(
        string $email,
        string $name,
        string $roleKey,
        ?Site $site,
    ): Employee {
        $employee = Employee::query()->where('email', $email)->first();
        if ($employee === null) {
            $employee = Employee::factory()->withoutRoleGrant()->create([
                'name' => $name,
                'email' => $email,
            ]);
        }

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        EmployeeRole::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'role_id' => $role->id,
                'site_id' => $site?->id,
            ],
            [
                'granted_by' => null,
            ],
        );

        return $employee;
    }
}
