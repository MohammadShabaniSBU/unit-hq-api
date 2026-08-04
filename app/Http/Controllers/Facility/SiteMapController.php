<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\LogChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\SiteMapResource;
use App\Models\Site;
use App\Models\SiteMap;
use App\Support\Facility\SiteMapIdMatcher;
use App\Support\Facility\SvgSanitizer;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class SiteMapController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        $columns = ['id', 'site_id', 'floor_name', 'sort_order', 'created_at', 'updated_at'];

        if ($request->boolean('with_svg')) {
            $columns[] = 'svg_map';
        }

        $maps = $site->siteMaps()->get($columns);

        return $this->success(
            SiteMapResource::collection($maps),
            'Site maps retrieved successfully.'
        );
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        $validated = $request->validate([
            'floor_name' => ['required', 'string', 'max:255'],
            'svg_map'    => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $sanitizedSvg = SvgSanitizer::sanitize($validated['svg_map']);

        $siteMap = DB::transaction(function () use ($site, $validated, $sanitizedSvg): SiteMap {
            $siteMap = $site->siteMaps()->create([
                'floor_name' => $validated['floor_name'],
                'svg_map'    => $sanitizedSvg,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            RecordsActivity::log(LogChannel::Facility, 'site_map.created', $siteMap, [
                'floor_name' => $siteMap->floor_name,
                'sort_order' => $siteMap->sort_order,
            ]);

            return $siteMap;
        });

        $siteMap->setAttribute('id_match', SiteMapIdMatcher::match($site, $sanitizedSvg));

        return $this->created(
            SiteMapResource::make($siteMap),
            'Site map created successfully.'
        );
    }

    public function show(SiteMap $siteMap): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $siteMap);

        return $this->success(
            SiteMapResource::make($siteMap),
            'Site map retrieved successfully.'
        );
    }

    public function update(Request $request, SiteMap $siteMap): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $siteMap);

        $validated = $request->validate([
            'floor_name' => ['sometimes', 'required', 'string', 'max:255'],
            'svg_map'    => ['sometimes', 'required', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $svgChanged = array_key_exists('svg_map', $validated);

        if ($svgChanged) {
            $validated['svg_map'] = SvgSanitizer::sanitize($validated['svg_map']);
        }

        DB::transaction(function () use ($siteMap, $validated, $svgChanged): void {
            $siteMap->update($validated);

            RecordsActivity::log(LogChannel::Facility, 'site_map.updated', $siteMap, [
                'floor_name'      => $siteMap->floor_name,
                'sort_order'      => $siteMap->sort_order,
                'svg_map_changed' => $svgChanged,
            ]);
        });

        $siteMap = $siteMap->fresh();

        if ($svgChanged) {
            $siteMap->setAttribute(
                'id_match',
                SiteMapIdMatcher::match($siteMap->site, $siteMap->svg_map)
            );
        }

        return $this->success(
            SiteMapResource::make($siteMap),
            'Site map updated successfully.'
        );
    }

    public function destroy(SiteMap $siteMap): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $siteMap);

        DB::transaction(function () use ($siteMap): void {
            RecordsActivity::log(LogChannel::Facility, 'site_map.deleted', $siteMap, [
                'floor_name' => $siteMap->floor_name,
            ]);

            $siteMap->delete();
        });

        return $this->noContent('Site map deleted successfully.');
    }

    /**
     * Sanitize + report id-match buckets for an SVG without persisting it.
     */
    public function validateSvg(Request $request, Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        $validated = $request->validate([
            'svg_map' => ['required', 'string'],
        ]);

        $sanitized = SvgSanitizer::sanitize($validated['svg_map']);

        return $this->success([
            'svg_map'  => $sanitized,
            'id_match' => SiteMapIdMatcher::match($site, $sanitized),
        ], 'Site map validated successfully.');
    }
}
