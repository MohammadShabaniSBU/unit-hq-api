<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\RoleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Named permission bundle. System roles are seeded; custom roles may arrive later.
 * Archive-only — never hard-deleted (historical grants reference them).
 *
 * @property int         $id
 * @property string      $key
 * @property string      $label
 * @property string|null $description
 * @property RoleScope   $scope_level
 * @property bool        $is_system
 * @property Carbon|null $archived_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, RolePermission> $rolePermissions
 * @property-read Collection<int, EmployeeRole>   $employeeRoles
 */
class Role extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'scope_level',
        'is_system',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_level' => RoleScope::class,
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<Role> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<Role> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return HasMany<RolePermission, $this> */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /** @return HasMany<EmployeeRole, $this> */
    public function employeeRoles(): HasMany
    {
        return $this->hasMany(EmployeeRole::class);
    }
}
