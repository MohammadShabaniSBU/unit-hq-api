<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\Permission;
use App\Support\Auth\PermissionMap;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property int         $id
 * @property string      $first_name
 * @property string      $last_name
 * @property string      $email
 * @property string|null $password
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_login_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 * @property-read string $name
 * @property-read string $status  invited|active|deactivated
 *
 * @property-read Collection<int, Price>              $createdPrices
 * @property-read Collection<int, Task>               $assignedTasks
 * @property-read Collection<int, Task>               $createdTasks
 * @property-read Collection<int, Note>               $notes
 * @property-read Collection<int, EmployeeRole>       $employeeRoles
 * @property-read Collection<int, EmployeeInvitation> $invitations
 */
#[Fillable([
    'first_name',
    'last_name',
    'name',
    'email',
    'password',
    'deactivated_at',
    'last_login_at',
])]
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
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Computed full name. Setter splits on first space so legacy factory/seeder
     * `'name' => '…'` assignments keep working after the column split.
     *
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim($this->first_name.' '.$this->last_name),
            set: function (string $value): array {
                $parts = explode(' ', trim($value), 2);

                return [
                    'first_name' => $parts[0] !== '' ? $parts[0] : 'Unknown',
                    'last_name' => $parts[1] ?? '',
                ];
            },
        );
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function isInvited(): bool
    {
        return ! $this->isDeactivated() && $this->password === null;
    }

    public function status(): string
    {
        if ($this->isDeactivated()) {
            return 'deactivated';
        }

        if ($this->isInvited()) {
            return 'invited';
        }

        return 'active';
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

    /** @return HasMany<EmployeeInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(EmployeeInvitation::class);
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
