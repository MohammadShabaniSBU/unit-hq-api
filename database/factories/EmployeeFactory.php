<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use WeakMap;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected static ?string $password;

    /** Sentinel: WeakMap cannot store null (offsetExists becomes false). */
    private const NO_GRANT = '__none__';

    /** @var WeakMap<Employee, string>|null */
    private static ?WeakMap $pendingGrants = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (Employee $employee): void {
                $map = self::pendingGrantMap();
                if (! $map->offsetExists($employee)) {
                    $map[$employee] = 'operations_manager';
                }
            })
            ->afterCreating(function (Employee $employee): void {
                $map = self::pendingGrantMap();
                $key = $map->offsetExists($employee) ? $map[$employee] : 'operations_manager';
                $map->offsetUnset($employee);

                if ($key === self::NO_GRANT) {
                    return;
                }

                self::grantCompanyRole($employee, $key);
            });
    }

    public function manager(): static
    {
        return $this->afterMaking(function (Employee $employee): void {
            self::pendingGrantMap()[$employee] = 'owner';
        });
    }

    public function staff(): static
    {
        return $this->afterMaking(function (Employee $employee): void {
            self::pendingGrantMap()[$employee] = 'operations_manager';
        });
    }

    public function withoutRoleGrant(): static
    {
        return $this->afterMaking(function (Employee $employee): void {
            self::pendingGrantMap()[$employee] = self::NO_GRANT;
        });
    }

    public static function grantCompanyRole(Employee $employee, string $roleKey): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $roleId = Role::query()->where('key', $roleKey)->value('id');
        if ($roleId === null) {
            return;
        }

        EmployeeRole::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'role_id' => (int) $roleId,
                'site_id' => null,
            ],
            [
                'granted_by' => null,
            ],
        );
    }

    /** @return WeakMap<Employee, string> */
    private static function pendingGrantMap(): WeakMap
    {
        return self::$pendingGrants ??= new WeakMap;
    }
}
