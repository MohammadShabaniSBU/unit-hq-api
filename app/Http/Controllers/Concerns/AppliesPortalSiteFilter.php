<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Support\Auth\Permission;
use App\Support\Auth\SitePath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Optional portal site-selector filter. Distinct from grant visibility ({@see VisibleToEmployee}).
 * null / omitted site_id = All sites (RBAC only).
 */
trait AppliesPortalSiteFilter
{
    /**
     * @param  Builder<Model>  $query
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    protected function applyPortalSiteFilter(
        Builder $query,
        Request $request,
        string $modelClass,
        Permission $permission,
    ): Builder {
        if (! $request->filled('site_id')) {
            return $query;
        }

        $siteId = $request->integer('site_id');

        /** @var Employee $employee */
        $employee = $request->user();
        $granted = $employee->siteIdsFor($permission);

        if ($granted === [] || ($granted !== null && ! in_array($siteId, $granted, true))) {
            throw ValidationException::withMessages([
                'site_id' => [__('errors.forbidden')],
            ]);
        }

        return SitePath::constrain($query, $modelClass, [$siteId]);
    }
}
