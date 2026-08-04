<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\Permission;
use App\Support\Auth\PermissionMap;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\HasApiTokens;

/**
 * Company staff who operate the dashboard.
 *
 * Authorization grants live on employee_roles (not a scalar role column).
 *
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Collection<int, Price>        $createdPrices
 * @property-read Collection<int, Task>         $assignedTasks
 * @property-read Collection<int, Task>         $createdTasks
 * @property-read Collection<int, Note>         $notes
 * @property-read Collection<int, EmployeeRole> $employeeRoles
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class Employee extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Per-request cache: permission value → null (company-wide) or site ids.
     *
     * @var array<string, list<int>|null>|null
     */
    private ?array $permissionMapCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * RBAC check when $abilities is a Permission; otherwise Gate (policies / ability gates).
     *
     * @param  Permission|string|iterable<mixed>  $abilities
     * @param  mixed  $arguments  Site when checking a Permission; Gate arguments otherwise
     */
    public function can($abilities, $arguments = []): bool
    {
        if ($abilities instanceof Permission) {
            $site = $arguments instanceof Site ? $arguments : null;

            return $this->allowsPermission($abilities, $site);
        }

        return Gate::forUser($this)->check($abilities, $arguments);
    }

    public function allowsPermission(Permission $permission, ?Site $site = null): bool
    {
        $map = $this->permissionMapCache ??= PermissionMap::for($this);

        if (! array_key_exists($permission->value, $map)) {
            return false;
        }

        $siteIds = $map[$permission->value];

        // Company-wide grant.
        if ($siteIds === null) {
            return true;
        }

        // Holds at specific site(s); null site = company-level subject check.
        if ($site === null) {
            return true;
        }

        return in_array($site->id, $siteIds, true);
    }

    public function forgetPermissionMap(): void
    {
        $this->permissionMapCache = null;
    }

    /**
     * Site ids granted for a permission.
     *
     * @return list<int>|null  null = company-wide; [] = nowhere; list = allowed sites
     */
    public function siteIdsFor(Permission $permission): ?array
    {
        $map = $this->permissionMapCache ??= PermissionMap::for($this);

        if (! array_key_exists($permission->value, $map)) {
            return [];
        }

        return $map[$permission->value];
    }

    /** @return HasMany<Price, $this> */
    public function createdPrices(): HasMany
    {
        return $this->hasMany(Price::class, 'created_by');
    }

    /** @return HasMany<Task, $this> */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /** @return HasMany<Task, $this> */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** @return HasMany<EmployeeRole, $this> */
    public function employeeRoles(): HasMany
    {
        return $this->hasMany(EmployeeRole::class);
    }

    /**
     * Employees holding a company-wide grant of the given system role key.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeWithCompanyRole(Builder $query, string $roleKey): void
    {
        $query->whereHas('employeeRoles', function (Builder $q) use ($roleKey): void {
            $q->whereNull('site_id')
                ->whereHas('role', fn (Builder $r) => $r->where('key', $roleKey));
        });
    }
}
