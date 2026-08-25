<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\SiteServiceAreaKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\SiteServiceAreaResource;
use App\Models\Site;
use App\Models\SiteServiceArea;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteServiceAreaController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value, $site);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = SiteServiceArea::query()
            ->where('site_id', $site->id)
            ->orderBy('kind')
            ->orderBy('value');

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->success(
            SiteServiceAreaResource::collection($query->get())->resolve(),
            'Service areas retrieved successfully.',
        );
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(SiteServiceAreaKind::class)],
            'value' => [
                'required',
                'string',
                'max:64',
                Rule::unique('site_service_areas', 'value')
                    ->where('site_id', $site->id)
                    ->where('kind', $request->input('kind'))
                    ->whereNull('archived_at'),
            ],
        ]);

        $area = SiteServiceArea::query()->create([
            'site_id' => $site->id,
            'kind' => $validated['kind'],
            'value' => trim($validated['value']),
            'archived_at' => null,
        ]);

        return $this->created(
            SiteServiceAreaResource::make($area),
            'Service area created successfully.',
        );
    }

    public function archive(SiteServiceArea $siteServiceArea): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $siteServiceArea->site);

        if ($siteServiceArea->isArchived()) {
            return $this->success(
                SiteServiceAreaResource::make($siteServiceArea),
                'Service area already archived.',
            );
        }

        $siteServiceArea->update(['archived_at' => now()]);

        return $this->success(
            SiteServiceAreaResource::make($siteServiceArea->fresh()),
            'Service area archived successfully.',
        );
    }

    public function unarchive(SiteServiceArea $siteServiceArea): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $siteServiceArea->site);

        if (! $siteServiceArea->isArchived()) {
            return $this->success(
                SiteServiceAreaResource::make($siteServiceArea),
                'Service area already active.',
            );
        }

        $taken = SiteServiceArea::query()
            ->where('site_id', $siteServiceArea->site_id)
            ->where('kind', $siteServiceArea->kind)
            ->where('value', $siteServiceArea->value)
            ->whereNull('archived_at')
            ->whereKeyNot($siteServiceArea->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'value' => ['A live service area with this kind and value already exists.'],
            ]);
        }

        $siteServiceArea->update(['archived_at' => null]);

        return $this->success(
            SiteServiceAreaResource::make($siteServiceArea->fresh()),
            'Service area restored successfully.',
        );
    }
}
