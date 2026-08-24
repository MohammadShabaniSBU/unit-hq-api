<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Resources\OfferCardResource;
use App\Http\Resources\OfferResource;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Unit;
use App\Support\Attributes\AppliesCreateAttributes;
use App\Support\Auth\Permission;
use App\Support\Discounts\DiscountSurface;
use App\Support\Facility\SiteMapLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OfferController extends Controller
{
    use AppliesPortalSiteFilter;
    use SearchesWithFilters;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $query = Offer::query()->visibleTo($employee, Permission::OfferManage)->with([
            'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads()),
            'contact',
        ])->latest();
        $this->applyPortalSiteFilter($query, $request, Offer::class, Permission::OfferManage);

        if ($request->filled('deal_id')) {
            $query->where('deal_id', $request->integer('deal_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Offer $offer) => OfferResource::make($offer)),
            'Offers retrieved successfully.'
        );
    }

    public function filterSchema(): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value);

        return $this->respondFilterSchema(AttributeEntityType::Offer);
    }

    public function search(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $query = Offer::query()->visibleTo($employee, Permission::OfferManage)->with([
            'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads()),
            'contact',
        ]);
        $this->applyPortalSiteFilter($query, $request, Offer::class, Permission::OfferManage);

        return $this->searchWithFilters(
            $request,
            AttributeEntityType::Offer,
            $query,
            fn (Offer $offer) => OfferResource::make($offer),
            'Offers retrieved successfully.',
            function ($query, Request $request): void {
                if ($request->filled('deal_id')) {
                    $query->where('deal_id', $request->integer('deal_id'));
                }
            },
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value);

        $validated = $request->validate([
            'deal_id' => ['required', 'integer', 'exists:deals,id'],
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'token' => ['nullable', 'string', 'unique:offers,token'],
            'status' => ['nullable', Rule::in(Offer::STATUSES)],
            'expires_at' => ['required', 'date'],
            'sent_at' => ['nullable', 'date'],
            'first_viewed_at' => ['nullable', 'date'],
            'accepted_at' => ['nullable', 'date'],
            'options' => ['nullable', 'array'],
            'options.*.unit_class_rate_id' => ['required', 'integer', 'exists:unit_class_rates,id'],
            'options.*.discount_id' => ['nullable', 'integer', 'exists:discounts,id'],
            'options.*.label' => ['required', 'string'],
            'options.*.description' => ['nullable', 'string'],
            'options.*.display_order' => ['required', 'integer', 'min:0'],
            ...AppliesCreateAttributes::validationRules(),
        ]);

        $options = $validated['options'] ?? [];
        $attributes = $validated['attributes'] ?? [];
        unset($validated['options'], $validated['attributes']);

        /** @var Employee|null $actor */
        $actor = $request->user();

        $offer = DB::transaction(function () use ($validated, $options, $attributes, $actor) {
            $validated['token'] ??= Str::random(64);

            $offer = Offer::query()->create($validated);

            foreach ($options as $optionData) {
                $optionData['unit_id'] = Unit::resolveUnitIdForRate($optionData['unit_class_rate_id']);
                $offer->options()->create($optionData);
            }

            AppliesCreateAttributes::apply(
                AttributeEntityType::Offer,
                $offer,
                $attributes,
                $actor,
            );

            return $offer;
        });

        return $this->created(
            OfferResource::make($offer->load([
                'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads()),
            ])),
            'Offer created successfully.'
        );
    }

    public function showByToken(string $token): JsonResponse
    {
        $offer = Offer::query()
            ->where('token', $token)
            ->with([
                'contact',
                'deal',
                'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads())
                    ->orderBy('display_order'),
            ])
            ->firstOrFail();

        if (is_null($offer->first_viewed_at)) {
            $offer->update([
                'first_viewed_at' => now(),
                'status' => 'viewed',
            ]);
        }

        $locale = DiscountSurface::normalizeLocale($offer->contact?->locale);
        App::setLocale($locale);

        $offer->options->each(fn (OfferOption $option) => $option->setRelation('offer', $offer));

        return $this->success(
            OfferResource::make($offer),
            'Offer retrieved successfully.'
        );
    }

    public function mapByToken(string $token, OfferOption $offerOption): JsonResponse
    {
        $offer = Offer::query()->where('token', $token)->firstOrFail();

        if ($offerOption->offer_id !== $offer->id) {
            abort(404);
        }

        $payload = SiteMapLocator::payloadForOption($offerOption);

        if ($payload === null) {
            return $this->notFound('No site map contains this unit.');
        }

        return $this->success($payload, 'Offer option map retrieved successfully.');
    }

    public function show(Offer $offer): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value, $offer);

        $offer->load([
            'deal',
            'contact',
            'notes',
            'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads()),
        ]);
        $offer->options->each(fn (OfferOption $option) => $option->setRelation('offer', $offer));

        return $this->success(
            OfferResource::make($offer),
            'Offer retrieved successfully.'
        );
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value, $offer);

        $validated = $request->validate([
            'deal_id' => ['sometimes', 'required', 'integer', 'exists:deals,id'],
            'contact_id' => ['sometimes', 'required', 'integer', 'exists:contacts,id'],
            'token' => ['sometimes', 'nullable', 'string', Rule::unique('offers', 'token')->ignore($offer->id)],
            'status' => ['sometimes', 'nullable', Rule::in(Offer::STATUSES)],
            'expires_at' => ['sometimes', 'required', 'date'],
            'sent_at' => ['sometimes', 'nullable', 'date'],
            'first_viewed_at' => ['sometimes', 'nullable', 'date'],
            'accepted_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $offer->update($validated);

        return $this->success(
            OfferResource::make($offer->fresh()->load([
                'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads()),
            ])),
            'Offer updated successfully.'
        );
    }

    public function updateStatus(Request $request, Offer $offer): JsonResponse
    {
        // Sending requires OfferSend; other status edits use OfferManage.
        if ($request->input('status') === 'sent') {
            Gate::authorize(Permission::OfferSend->value, $offer);
        } else {
            Gate::authorize(Permission::OfferManage->value, $offer);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(Offer::STATUSES)],
        ]);

        $offer->update(['status' => $validated['status']]);

        return $this->success(
            OfferCardResource::make($offer->fresh()->load('contact')->loadCount('options')),
            'Offer status updated successfully.'
        );
    }

    public function destroy(Offer $offer): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value, $offer);

        $offer->delete();

        return $this->noContent('Offer deleted successfully.');
    }
}
