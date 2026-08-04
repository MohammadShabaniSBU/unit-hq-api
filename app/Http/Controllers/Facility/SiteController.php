<?php

namespace App\Http\Controllers\Facility;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Support\Billing\SupportedCurrencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class SiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = Site::query()->visibleTo($employee, Permission::UnitView)->with('country')->latest();

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Site $site) => SiteResource::make($site)),
            'Sites retrieved successfully.'
        );
    }

    public function options(Request $request): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $options = Site::query()->visibleTo($employee, Permission::UnitView)->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $site) => ['value' => $site->id, 'label' => $site->name]);

        return $this->success($options, 'Site options retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value);

        $validated = $this->validatedPayload($request, creating: true);

        $site = Site::query()->create($validated);

        return $this->created(
            SiteResource::make($site->load(['country', 'legalEntity'])),
            'Site created successfully.'
        );
    }

    public function show(Site $site): JsonResponse
    {
        Gate::authorize(Permission::UnitView->value, $site);

        return $this->success(
            SiteResource::make($site->load(['country', 'legalEntity'])),
            'Site retrieved successfully.'
        );
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        $validated = $this->validatedPayload($request, creating: false);

        $site->update($validated);

        return $this->success(
            SiteResource::make($site->fresh()->load(['country', 'legalEntity'])),
            'Site updated successfully.'
        );
    }

    public function archive(Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        if ($site->isArchived()) {
            return $this->success(
                SiteResource::make($site->load(['country', 'legalEntity'])),
                'Site already archived.'
            );
        }

        $this->assertCanArchive($site);

        $site->update(['archived_at' => now()]);

        return $this->success(
            SiteResource::make($site->fresh()->load(['country', 'legalEntity'])),
            'Site archived successfully.'
        );
    }

    public function unarchive(Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        if (! $site->isArchived()) {
            return $this->success(
                SiteResource::make($site->load(['country', 'legalEntity'])),
                'Site already active.'
            );
        }

        $site->update(['archived_at' => null]);

        return $this->success(
            SiteResource::make($site->fresh()->load(['country', 'legalEntity'])),
            'Site unarchived successfully.'
        );
    }

    public function destroy(Site $site): JsonResponse
    {
        Gate::authorize(Permission::SiteManage->value, $site);

        if (! $site->isArchived()) {
            $this->assertCanArchive($site);
            $site->update(['archived_at' => now()]);
        }

        return $this->noContent('Site archived successfully.');
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $creating): array
    {
        $timezoneRule = $creating
            ? ['required', 'timezone:all']
            : ['sometimes', 'required', 'timezone:all'];

        /** @var Site|string|null $routeSite */
        $routeSite = $request->route('site');
        $ignoreId = $routeSite instanceof Site ? $routeSite->id : $routeSite;

        if ($request->filled('currency')) {
            $request->merge([
                'currency' => SupportedCurrencies::normalize((string) $request->input('currency')),
            ]);
        }

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('sites', 'code')->ignore($ignoreId)],
            'address' => ['nullable', 'string'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['nullable', 'numeric'],
            'location.lng' => ['nullable', 'numeric'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'state_region' => ['nullable', 'string', 'max:255'],
            'country_id' => [$creating ? 'required' : 'sometimes', 'required', 'integer', Rule::exists('countries', 'id')],
            'timezone' => $timezoneRule,
            'currency' => SupportedCurrencies::rules(required: false),
            'legal_entity_id' => [
                $creating ? 'required' : 'sometimes',
                'required',
                'integer',
                Rule::exists('legal_entities', 'id')->whereNull('archived_at'),
            ],
            'delinquency_policy_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('delinquency_policies', 'id')->whereNull('archived_at'),
            ],
        ]);
    }

    private function assertCanArchive(Site $site): void
    {
        $counts = $site->occupancyBlockCounts();

        if ($counts['active_contracts'] > 0 || $counts['active_reservations'] > 0) {
            throw ValidationException::withMessages([
                'site' => [
                    sprintf(
                        'Cannot archive site with %d active contract(s) and %d active reservation(s).',
                        $counts['active_contracts'],
                        $counts['active_reservations']
                    ),
                ],
                'active_contracts' => [$counts['active_contracts']],
                'active_reservations' => [$counts['active_reservations']],
            ]);
        }
    }
}
