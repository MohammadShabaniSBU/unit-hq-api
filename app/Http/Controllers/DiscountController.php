<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DiscountKind;
use App\Http\Resources\DiscountResource;
use App\Models\Deal;
use App\Models\Discount;
use App\Support\Discounts\DiscountSurface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class DiscountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = Discount::query()
            ->withCount(['offerOptions', 'contractItems'])
            ->orderBy('name');

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->success(
            DiscountResource::collection($query->get())->resolve(),
            'Discounts retrieved successfully.'
        );
    }

    public function options(): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value);

        $options = Discount::query()->active()->orderBy('name')->get(['id', 'name', 'kind'])
            ->map(fn (Discount $discount) => [
                'value' => $discount->id,
                'label' => $discount->name,
                'kind' => $discount->kind->value,
            ]);

        return $this->success($options, 'Discount options retrieved successfully.');
    }

    public function resolve(Request $request, Discount $discount): JsonResponse
    {
        Gate::authorize(Permission::OfferManage->value, $discount);

        $validated = $request->validate([
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'commitment_weeks' => ['nullable', 'integer', 'min:1'],
            'list_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'string', 'max:16'],
            'anchor_date' => ['nullable', 'date'],
        ]);

        $deal = isset($validated['deal_id'])
            ? Deal::query()->find($validated['deal_id'])
            : null;

        $locale = DiscountSurface::normalizeLocale(
            $validated['locale'] ?? $request->getPreferredLanguage(['en', 'es', 'fr'])
        );
        App::setLocale($locale);

        $listAmount = isset($validated['list_amount'])
            ? number_format((float) $validated['list_amount'], 2, '.', '')
            : null;

        $payload = DiscountSurface::resolve(
            discount: $discount,
            deal: $deal,
            commitmentWeeks: isset($validated['commitment_weeks'])
                ? (int) $validated['commitment_weeks']
                : null,
            listAmount: $listAmount,
            currency: isset($validated['currency'])
                ? strtoupper($validated['currency'])
                : null,
            locale: $locale,
            anchorDate: $validated['anchor_date'] ?? null,
        );

        return $this->success($payload, 'Discount resolution retrieved successfully.');
    }

    public function show(Discount $discount): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $discount);

        $discount->loadCount(['offerOptions', 'contractItems']);

        return $this->success(
            DiscountResource::make($discount),
            'Discount retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value);

        $validated = $this->validatedPayload($request, creating: true);

        $discount = Discount::query()->create([
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'params' => $validated['params'],
            'applies_to' => $validated['applies_to'] ?? 'unit',
            'tracks_rate_changes' => $validated['tracks_rate_changes'],
            'created_by' => $request->user()?->id,
        ]);

        $discount->loadCount(['offerOptions', 'contractItems']);

        return $this->created(
            DiscountResource::make($discount),
            'Discount created successfully.'
        );
    }

    public function update(Request $request, Discount $discount): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $discount);

        $validated = $this->validatedPayload($request, creating: false, discount: $discount);

        $attributes = [];
        if (array_key_exists('name', $validated)) {
            $attributes['name'] = $validated['name'];
        }
        if (array_key_exists('kind', $validated)) {
            $attributes['kind'] = $validated['kind'];
        }
        if (array_key_exists('params', $validated)) {
            $attributes['params'] = $validated['params'];
        }
        if (array_key_exists('applies_to', $validated)) {
            $attributes['applies_to'] = $validated['applies_to'];
        }
        if (array_key_exists('tracks_rate_changes', $validated)) {
            $attributes['tracks_rate_changes'] = $validated['tracks_rate_changes'];
        }

        if ($attributes !== []) {
            $discount->update($attributes);
        }

        $discount->loadCount(['offerOptions', 'contractItems']);

        return $this->success(
            DiscountResource::make($discount->fresh()->loadCount(['offerOptions', 'contractItems'])),
            'Discount updated successfully.'
        );
    }

    public function archive(Discount $discount): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $discount);

        if ($discount->isArchived()) {
            $discount->loadCount(['offerOptions', 'contractItems']);

            return $this->success(
                DiscountResource::make($discount),
                'Discount is already archived.'
            );
        }

        $this->assertCanArchive($discount);

        $discount->update(['archived_at' => now()]);
        $discount->loadCount(['offerOptions', 'contractItems']);

        return $this->success(
            DiscountResource::make($discount->fresh()->loadCount(['offerOptions', 'contractItems'])),
            'Discount archived successfully.'
        );
    }

    public function unarchive(Discount $discount): JsonResponse
    {
        Gate::authorize(Permission::CatalogueManage->value, $discount);

        if (! $discount->isArchived()) {
            $discount->loadCount(['offerOptions', 'contractItems']);

            return $this->success(
                DiscountResource::make($discount),
                'Discount is already active.'
            );
        }

        $discount->update(['archived_at' => null]);
        $discount->loadCount(['offerOptions', 'contractItems']);

        return $this->success(
            DiscountResource::make($discount->fresh()->loadCount(['offerOptions', 'contractItems'])),
            'Discount unarchived successfully.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $creating, ?Discount $discount = null): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';

        $validated = $request->validate([
            'name' => [$sometimes, 'string', 'max:128'],
            'kind' => [$sometimes, Rule::enum(DiscountKind::class)],
            // `present` (not `required`) so an empty array reaches kind-specific checks.
            'params' => [$creating ? 'present' : 'sometimes', 'array'],
            'applies_to' => ['sometimes', 'string', Rule::in(['unit'])],
            'tracks_rate_changes' => ['sometimes', 'boolean'],
        ]);

        $kind = isset($validated['kind'])
            ? DiscountKind::from($validated['kind'])
            : $discount?->kind;

        if ($kind === null) {
            throw ValidationException::withMessages([
                'kind' => ['The kind field is required.'],
            ]);
        }

        $params = array_key_exists('params', $validated)
            ? $validated['params']
            : ($discount?->params ?? null);

        if ($creating || array_key_exists('params', $validated) || array_key_exists('kind', $validated)) {
            if (! is_array($params)) {
                throw ValidationException::withMessages([
                    'params' => ['The params field is required.'],
                ]);
            }

            $validated['params'] = $this->validateParams($kind, $params);
        }

        if ($kind === DiscountKind::FreeTime) {
            $validated['tracks_rate_changes'] = false;
        } elseif (! array_key_exists('tracks_rate_changes', $validated)) {
            $validated['tracks_rate_changes'] = $creating
                ? true
                : (bool) ($discount?->tracks_rate_changes ?? true);
        }

        $validated['kind'] = $kind->value;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validateParams(DiscountKind $kind, array $params): array
    {
        return match ($kind) {
            DiscountKind::Percent => $this->validatePercentParams($params),
            DiscountKind::FreeTime => $this->validateFreeTimeParams($params),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{percent: string}
     */
    private function validatePercentParams(array $params): array
    {
        $validator = Validator::make($params, [
            'percent' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->messages())
                    ->mapWithKeys(fn (array $messages, string $key) => ["params.{$key}" => $messages])
                    ->all()
            );
        }

        $percent = number_format((float) $params['percent'], 2, '.', '');

        if (bccomp($percent, '0.00', 2) <= 0 || bccomp($percent, '100.00', 2) >= 0) {
            throw ValidationException::withMessages([
                'params.percent' => ['The percent must be greater than 0 and less than 100.'],
            ]);
        }

        return ['percent' => $percent];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{tiers: array<int, array{min_commitment_weeks: int, free_weeks: int}>}
     */
    private function validateFreeTimeParams(array $params): array
    {
        $validator = Validator::make($params, [
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.min_commitment_weeks' => ['required', 'integer', 'min:1'],
            'tiers.*.free_weeks' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->messages())
                    ->mapWithKeys(fn (array $messages, string $key) => ["params.{$key}" => $messages])
                    ->all()
            );
        }

        /** @var array<int, array{min_commitment_weeks: int, free_weeks: int}> $tiers */
        $tiers = [];
        foreach ($params['tiers'] as $index => $tier) {
            $min = (int) $tier['min_commitment_weeks'];
            $free = (int) $tier['free_weeks'];

            if ($free >= $min) {
                throw ValidationException::withMessages([
                    "params.tiers.{$index}.free_weeks" => [
                        'Free weeks must be less than the minimum commitment weeks.',
                    ],
                ]);
            }

            $tiers[] = [
                'min_commitment_weeks' => $min,
                'free_weeks' => $free,
            ];
        }

        for ($i = 1, $count = count($tiers); $i < $count; $i++) {
            if ($tiers[$i]['min_commitment_weeks'] <= $tiers[$i - 1]['min_commitment_weeks']) {
                throw ValidationException::withMessages([
                    "params.tiers.{$i}.min_commitment_weeks" => [
                        'Minimum commitment weeks must be strictly increasing.',
                    ],
                ]);
            }

            if ($tiers[$i]['free_weeks'] <= $tiers[$i - 1]['free_weeks']) {
                throw ValidationException::withMessages([
                    "params.tiers.{$i}.free_weeks" => [
                        'Free weeks must be strictly increasing.',
                    ],
                ]);
            }
        }

        return ['tiers' => $tiers];
    }

    private function assertCanArchive(Discount $discount): void
    {
        $count = $discount->usageCount();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'discount' => [
                    __('errors.discounts.archive_in_use', ['count' => $count]),
                ],
            ]);
        }
    }
}
