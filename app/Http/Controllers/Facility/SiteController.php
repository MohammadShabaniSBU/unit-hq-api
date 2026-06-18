<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->paginated(
            Site::query()->with('country')->latest()->paginate($this->perPage())->through(fn (Site $site) => SiteResource::make($site)),
            'Sites retrieved successfully.'
        );
    }

    public function options(): JsonResponse
    {
        $options = Site::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $site) => ['value' => $site->id, 'title' => $site->name]);

        return $this->success($options, 'Site options retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'address'       => ['nullable', 'string'],
            'location'      => ['nullable', 'array'],
            'location.lat'  => ['nullable', 'numeric'],
            'location.lng'  => ['nullable', 'numeric'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'city'          => ['nullable', 'string', 'max:255'],
            'country_id'    => ['nullable', 'integer', Rule::exists('countries', 'id')],
        ]);

        $site = Site::query()->create($validated);

        return $this->created(
            SiteResource::make($site->load('country')),
            'Site created successfully.'
        );
    }

    public function show(Site $site): JsonResponse
    {
        return $this->success(
            SiteResource::make($site->load('country')),
            'Site retrieved successfully.'
        );
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['sometimes', 'required', 'string', 'max:255'],
            'address'       => ['nullable', 'string'],
            'location'      => ['nullable', 'array'],
            'location.lat'  => ['nullable', 'numeric'],
            'location.lng'  => ['nullable', 'numeric'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'city'          => ['nullable', 'string', 'max:255'],
            'country_id'    => ['nullable', 'integer', Rule::exists('countries', 'id')],
        ]);

        $site->update($validated);

        return $this->success(
            SiteResource::make($site->fresh()->load('country')),
            'Site updated successfully.'
        );
    }

    public function destroy(Site $site): JsonResponse
    {
        $site->delete();

        return $this->noContent('Site deleted successfully.');
    }
}
