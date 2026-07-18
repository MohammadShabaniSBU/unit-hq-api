<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DiscountType;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DiscountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Discount::query()->latest();

        if ($request->filled('code')) {
            $query->where('code', 'like', '%' . $request->string('code') . '%');
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Discount $discount) => DiscountResource::make($discount)),
            'Discounts retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateDiscount($request);

        $discount = Discount::query()->create($validated);

        return $this->created(
            DiscountResource::make($discount),
            'Discount created successfully.'
        );
    }

    public function show(Discount $discount): JsonResponse
    {
        return $this->success(
            DiscountResource::make($discount),
            'Discount retrieved successfully.'
        );
    }

    public function update(Request $request, Discount $discount): JsonResponse
    {
        $validated = $this->validateDiscount($request, isUpdate: true, discount: $discount);

        $discount->update($validated);

        return $this->success(
            DiscountResource::make($discount->fresh()),
            'Discount updated successfully.'
        );
    }

    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return $this->noContent('Discount deleted successfully.');
    }

    /** @return array<string, mixed> */
    private function validateDiscount(Request $request, bool $isUpdate = false, ?Discount $discount = null): array
    {
        $sometimes = $isUpdate ? 'sometimes' : 'required';

        $validated = $request->validate([
            'code'            => ['nullable', 'string', 'max:255'],
            'label'           => [$sometimes, 'string', 'max:255'],
            'discount_type'   => [$sometimes, Rule::enum(DiscountType::class)],
            'value'           => [$sometimes, 'numeric', 'min:0'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
            'effective_from'  => ['nullable', 'date'],
            'effective_to'    => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $discountType = isset($validated['discount_type'])
            ? DiscountType::from($validated['discount_type'])
            : $discount?->discount_type;

        if (
            $discountType === DiscountType::Percentage
            && isset($validated['value'])
            && (float) $validated['value'] > 100
        ) {
            throw ValidationException::withMessages([
                'value' => ['The value must not be greater than 100 for percentage discounts.'],
            ]);
        }

        return $validated;
    }
}
