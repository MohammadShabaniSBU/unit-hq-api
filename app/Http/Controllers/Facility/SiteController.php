<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->paginated(
            Site::query()->latest()->paginate($this->perPage())->through(fn (Site $site) => SiteResource::make($site)),
            'Sites retrieved successfully.'
        );
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
            'country'       => ['nullable', 'string', 'max:255'],
        ]);

        $site = Site::query()->create($validated);

        return $this->created(
            SiteResource::make($site),
            'Site created successfully.'
        );
    }

    public function show(Site $site): JsonResponse
    {
        return $this->success(
            SiteResource::make($site),
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
            'country'       => ['nullable', 'string', 'max:255'],
        ]);

        $site->update($validated);

        return $this->success(
            SiteResource::make($site->fresh()),
            'Site updated successfully.'
        );
    }

    public function destroy(Site $site): JsonResponse
    {
        $site->delete();

        return $this->noContent('Site deleted successfully.');
    }
}
