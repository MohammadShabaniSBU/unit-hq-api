<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteMapResource;
use App\Models\Site;
use App\Models\SiteMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteMapController extends Controller
{
    public function index(Site $site): JsonResponse
    {
        $maps = $site->siteMaps()
            ->get(['id', 'site_id', 'floor_name', 'sort_order', 'created_at', 'updated_at']);

        return $this->success(
            SiteMapResource::collection($maps),
            'Site maps retrieved successfully.'
        );
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'floor_name' => ['required', 'string', 'max:255'],
            'svg_map'    => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $siteMap = $site->siteMaps()->create([
            'floor_name' => $validated['floor_name'],
            'svg_map'    => $validated['svg_map'],
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return $this->created(
            SiteMapResource::make($siteMap),
            'Site map created successfully.'
        );
    }

    public function show(SiteMap $siteMap): JsonResponse
    {
        return $this->success(
            SiteMapResource::make($siteMap),
            'Site map retrieved successfully.'
        );
    }

    public function update(Request $request, SiteMap $siteMap): JsonResponse
    {
        $validated = $request->validate([
            'floor_name' => ['sometimes', 'required', 'string', 'max:255'],
            'svg_map'    => ['sometimes', 'required', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $siteMap->update($validated);

        return $this->success(
            SiteMapResource::make($siteMap->fresh()),
            'Site map updated successfully.'
        );
    }

    public function destroy(SiteMap $siteMap): JsonResponse
    {
        $siteMap->delete();

        return $this->noContent('Site map deleted successfully.');
    }
}
