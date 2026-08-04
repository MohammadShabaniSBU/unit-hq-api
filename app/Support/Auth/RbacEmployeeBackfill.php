<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Support\RecordsActivity;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Behaviour-preserving migration of employees.role → employee_roles grants.
 * Callable from the backfill migration and from tests.
 */
final class RbacEmployeeBackfill
{
    public static function run(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        if (! Schema::hasColumn('employees', 'role')) {
            return;
        }

        $ownerId = (int) Role::query()->where('key', 'owner')->value('id');
        $opsId = (int) Role::query()->where('key', 'operations_manager')->value('id');

        $employees = DB::table('employees')->select(['id', 'role'])->orderBy('id')->get();

        foreach ($employees as $row) {
            $roleKey = (string) $row->role;
            $targetRoleId = $roleKey === 'manager' ? $ownerId : $opsId;

            EmployeeRole::query()->firstOrCreate(
                [
                    'employee_id' => (int) $row->id,
                    'role_id' => $targetRoleId,
                    'site_id' => null,
                ],
                [
                    'granted_by' => null,
                ],
            );
        }

        $ownerCount = EmployeeRole::query()
            ->where('role_id', $ownerId)
            ->whereNull('site_id')
            ->count();

        if ($ownerCount === 0) {
            $lowest = Employee::query()->orderBy('id')->first();
            if ($lowest === null) {
                return;
            }

            $grant = EmployeeRole::query()->firstOrCreate(
                [
                    'employee_id' => $lowest->id,
                    'role_id' => $ownerId,
                    'site_id' => null,
                ],
                [
                    'granted_by' => null,
                ],
            );

            RecordsActivity::core('rbac.owner.promoted', $lowest, [
                'employee_id' => (string) $lowest->id,
                'employee_role_id' => (string) $grant->id,
                'reason' => 'backfill_no_owner',
            ], anonymous: true);
        }
    }
}
