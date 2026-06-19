<?php

namespace App\Http\Controllers;

use App\Http\Resources\OfferResource;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OfferController extends Controller
{
    /** @var array<int, string> */
    private const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'expired'];

    public function index(Request $request): JsonResponse
    {
        $query = Offer::query()->with(['options', 'contact'])->latest();

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deal_id'         => ['required', 'integer', 'exists:deals,id'],
            'contact_id'      => ['required', 'integer', 'exists:contacts,id'],
            'token'           => ['nullable', 'string', 'unique:offers,token'],
            'status'          => ['nullable', Rule::in(self::STATUSES)],
            'expires_at'      => ['required', 'date'],
            'sent_at'         => ['nullable', 'date'],
            'first_viewed_at' => ['nullable', 'date'],
            'accepted_at'     => ['nullable', 'date'],
            'options'         => ['nullable', 'array'],
            'options.*.unit_class_id'  => ['required', 'integer', 'exists:unit_classes,id'],
            'options.*.price_id'       => ['required', 'integer', 'exists:prices,id'],
            'options.*.discount_id'    => ['nullable', 'integer', 'exists:discounts,id'],
            'options.*.label'          => ['required', 'string'],
            'options.*.description'    => ['nullable', 'string'],
            'options.*.display_order'  => ['required', 'integer', 'min:0'],
        ]);

        $options = $validated['options'] ?? [];
        unset($validated['options']);

        $offer = DB::transaction(function () use ($validated, $options) {
            $validated['token'] ??= Str::random(64);

            $offer = Offer::query()->create($validated);

            foreach ($options as $optionData) {
                $offer->options()->create($optionData);
            }

            return $offer;
        });

        return $this->created(
            OfferResource::make($offer->load('options')),
            'Offer created successfully.'
        );
    }

    public function show(Offer $offer): JsonResponse
    {
        return $this->success(
            OfferResource::make($offer->load(['options', 'deal', 'contact'])),
            'Offer retrieved successfully.'
        );
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        $validated = $request->validate([
            'deal_id'         => ['sometimes', 'required', 'integer', 'exists:deals,id'],
            'contact_id'      => ['sometimes', 'required', 'integer', 'exists:contacts,id'],
            'token'           => ['sometimes', 'nullable', 'string', Rule::unique('offers', 'token')->ignore($offer->id)],
            'status'          => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'expires_at'      => ['sometimes', 'required', 'date'],
            'sent_at'         => ['sometimes', 'nullable', 'date'],
            'first_viewed_at' => ['sometimes', 'nullable', 'date'],
            'accepted_at'     => ['sometimes', 'nullable', 'date'],
        ]);

        $offer->update($validated);

        return $this->success(
            OfferResource::make($offer->fresh()->load('options')),
            'Offer updated successfully.'
        );
    }

    public function destroy(Offer $offer): JsonResponse
    {
        $offer->delete();

        return $this->noContent('Offer deleted successfully.');
    }
}
