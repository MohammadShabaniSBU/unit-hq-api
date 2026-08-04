<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\OwnerFloor;
use App\Support\Auth\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A role grant for an employee, optionally scoped to a site.
 * site_id NULL = company-wide (authorization scope only — never ambient data scope).
 *
 * @property int         $id
 * @property int         $employee_id
 * @property int         $role_id
 * @property int|null    $site_id
 * @property int|null    $granted_by
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Employee      $employee
 * @property-read Role          $role
 * @property-read Site|null     $site
 * @property-read Employee|null $grantedBy
 */
class EmployeeRole extends Model
{
    protected $fillable = [
        'employee_id',
        'role_id',
        'site_id',
        'granted_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (EmployeeRole $grant): void {
            $grant->assertScopeMatchesRole();
        });

        static::deleting(function (EmployeeRole $grant): void {
            OwnerFloor::assertCanRevoke($grant);
        });
    }

    /**
     * @throws ValidationException
     */
    public function assertScopeMatchesRole(): void
    {
        $role = $this->relationLoaded('role')
            ? $this->role
            : Role::query()->find($this->role_id);

        if ($role === null) {
            throw ValidationException::withMessages([
                'role_id' => [__('errors.rbac.role_missing')],
            ]);
        }

        $scope = $role->scope_level;
        $hasSite = $this->site_id !== null;

        if ($scope === RoleScope::Company && $hasSite) {
            throw ValidationException::withMessages([
                'site_id' => [__('errors.rbac.company_role_rejects_site')],
            ]);
        }

        if ($scope === RoleScope::Site && ! $hasSite) {
            throw ValidationException::withMessages([
                'site_id' => [__('errors.rbac.site_role_requires_site')],
            ]);
        }
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'granted_by');
    }
}
