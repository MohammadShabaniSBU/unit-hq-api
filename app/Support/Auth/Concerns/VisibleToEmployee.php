<?php

declare(strict_types=1);

namespace App\Support\Auth\Concerns;

use App\Models\Employee;
use App\Support\Auth\Permission;
use App\Support\Auth\SitePath;
use Illuminate\Database\Eloquent\Builder;

/**
 * Explicit list-visibility scope. Never register as a global scope.
 *
 * @method static Builder<static> visibleTo(Employee $employee, Permission $permission)
 */
trait VisibleToEmployee
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, Employee $employee, Permission $permission): Builder
    {
        $siteIds = $employee->siteIdsFor($permission);

        // Permission absent → empty set (endpoint reachability is task 03's job).
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        // Company-wide grant → no filter (common case; must cost nothing).
        if ($siteIds === null) {
            return $query;
        }

        // Model has no site path → company-level; any site grant still sees all rows.
        if (! SitePath::hasSitePath(static::class)) {
            return $query;
        }

        return SitePath::constrain($query, static::class, $siteIds);
    }
}
