<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Support\Auth\RbacEmployeeBackfill;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RbacBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function existing_employees_keep_capability(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();
        EmployeeRole::query()->delete();

        Schema::table('employees', function ($table): void {
            $table->string('role')->default('staff');
        });

        $managerId = DB::table('employees')->insertGetId([
            'name' => 'Legacy Manager',
            'email' => 'legacy-manager@example.com',
            'password' => bcrypt('password'),
            'role' => 'manager',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staffId = DB::table('employees')->insertGetId([
            'name' => 'Legacy Staff',
            'email' => 'legacy-staff@example.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RbacEmployeeBackfill::run();

        $ownerId = (int) Role::query()->where('key', 'owner')->value('id');
        $opsId = (int) Role::query()->where('key', 'operations_manager')->value('id');

        $this->assertTrue(
            EmployeeRole::query()
                ->where('employee_id', $managerId)
                ->where('role_id', $ownerId)
                ->whereNull('site_id')
                ->exists(),
        );
        $this->assertTrue(
            EmployeeRole::query()
                ->where('employee_id', $staffId)
                ->where('role_id', $opsId)
                ->whereNull('site_id')
                ->exists(),
        );

        Schema::table('employees', function ($table): void {
            $table->dropColumn('role');
        });
    }

    #[Test]
    public function promotes_an_owner_when_none_would_exist(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();
        EmployeeRole::query()->delete();

        Schema::table('employees', function ($table): void {
            $table->string('role')->default('staff');
        });

        $firstId = DB::table('employees')->insertGetId([
            'name' => 'Only Staff A',
            'email' => 'staff-a@example.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'name' => 'Only Staff B',
            'email' => 'staff-b@example.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RbacEmployeeBackfill::run();

        $ownerId = (int) Role::query()->where('key', 'owner')->value('id');
        $opsId = (int) Role::query()->where('key', 'operations_manager')->value('id');

        $this->assertTrue(
            EmployeeRole::query()
                ->where('employee_id', $firstId)
                ->where('role_id', $ownerId)
                ->whereNull('site_id')
                ->exists(),
            'Lowest-id employee must be promoted to owner',
        );
        $this->assertTrue(
            EmployeeRole::query()
                ->where('employee_id', $firstId)
                ->where('role_id', $opsId)
                ->whereNull('site_id')
                ->exists(),
            'Staff grant remains; promotion adds owner',
        );

        $this->assertTrue(
            Activity::query()
                ->where('description', 'rbac.owner.promoted')
                ->where('subject_type', Employee::class)
                ->where('subject_id', $firstId)
                ->exists(),
        );

        Schema::table('employees', function ($table): void {
            $table->dropColumn('role');
        });
    }
}
