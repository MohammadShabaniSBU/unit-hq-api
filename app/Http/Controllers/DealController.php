<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\DealStatus;
use App\Enums\StayPeriod;
use App\Enums\StorageReason;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Resources\DealCardResource;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class DealController extends Controller
{
    use SearchesWithFilters;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value);

        $query = Deal::query()->with(['desiredUnitClass', 'contact'])->latest();

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->integer('contact_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Deal $deal) => DealResource::make($deal)),
            'Deals retrieved successfully.'
        );
    }

    public function filterSchema(): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value);

        return $this->respondFilterSchema(AttributeEntityType::Deal);
    }

    public function search(Request $request): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value);

        return $this->searchWithFilters(
            $request,
            AttributeEntityType::Deal,
            Deal::query()->with(['desiredUnitClass', 'contact']),
            fn (Deal $deal) => DealResource::make($deal),
            'Deals retrieved successfully.',
            function ($query, Request $request): void {
                if ($request->filled('contact_id')) {
                    $query->where('contact_id', $request->integer('contact_id'));
                }
            },
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value);

        $validated = $request->validate([
            'contact_id'            => ['required', 'integer', 'exists:contacts,id'],
            'site_id'               => ['nullable', 'integer', 'exists:sites,id'],
            'status'                => ['nullable', Rule::enum(DealStatus::class)],
            'expected_move_in'      => ['nullable', 'date'],
            'expected_stay_length'  => ['nullable', 'integer', 'min:1'],
            'expected_stay_period'  => ['nullable', Rule::enum(StayPeriod::class)],
            'storage_reason'        => ['nullable', Rule::enum(StorageReason::class)],
            'desired_size'          => ['nullable', 'numeric', 'min:0'],
            'desired_unit_class_id' => ['nullable', 'integer', 'exists:unit_classes,id'],
        ]);

        $deal = Deal::query()->create($validated);

        RecordsActivity::core('deal.created', $deal, [
            'status' => $deal->status?->value ?? $deal->status,
        ]);

        return $this->created(
            DealResource::make($deal->load('desiredUnitClass')),
            'Deal created successfully.'
        );
    }

    public function show(Deal $deal): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value, $deal);

        $deal->load([
            'contact',
            'desiredUnitClass',
            'offers.options.unitClassRate.unitClass',
            'offers.options.unitClassRate.site',
            'offers.options.unitClassRate.price',
            'reservations.unit.site',
            'reservations.unit.unitClass',
            'contracts.items.item',
            'contracts.reservation',
            'tasks',
            'notes',
        ]);

        return $this->success(
            DealResource::make($deal)->additional([
                'include_playbook_enrolment' => true,
            ]),
            'Deal retrieved successfully.'
        );
    }

    public function update(Request $request, Deal $deal): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value, $deal);

        $validated = $request->validate([
            'contact_id'            => ['sometimes', 'required', 'integer', 'exists:contacts,id'],
            'site_id'               => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
            'status'                => ['sometimes', 'nullable', Rule::enum(DealStatus::class)],
            'expected_move_in'      => ['sometimes', 'nullable', 'date'],
            'expected_stay_length'  => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expected_stay_period'  => ['sometimes', 'nullable', Rule::enum(StayPeriod::class)],
            'storage_reason'        => ['sometimes', 'nullable', Rule::enum(StorageReason::class)],
            'desired_size'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'desired_unit_class_id' => ['sometimes', 'nullable', 'integer', 'exists:unit_classes,id'],
        ]);

        $previousStatus = $deal->status;
        $deal->update($validated);
        $deal = $deal->fresh()->load('desiredUnitClass');

        if (array_key_exists('status', $validated)) {
            $newStatus = $deal->status;
            $from = $previousStatus instanceof DealStatus ? $previousStatus->value : $previousStatus;
            $to = $newStatus instanceof DealStatus ? $newStatus->value : $newStatus;

            if ($from !== $to) {
                RecordsActivity::core('deal.stage_changed', $deal, [
                    'from' => $from,
                    'to' => $to,
                ]);

                if ($newStatus === DealStatus::ClosedWon) {
                    RecordsActivity::core('deal.won', $deal, ['status' => $to]);
                } elseif ($newStatus === DealStatus::ClosedLost) {
                    RecordsActivity::core('deal.lost', $deal, ['status' => $to]);
                }
            }
        }

        return $this->success(
            DealResource::make($deal),
            'Deal updated successfully.'
        );
    }

    public function updateStatus(Request $request, Deal $deal): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value, $deal);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(DealStatus::class)],
        ]);

        $previousStatus = $deal->status;
        $deal->update(['status' => $validated['status']]);
        $deal = $deal->fresh()->load(['contact', 'desiredUnitClass']);

        $from = $previousStatus instanceof DealStatus ? $previousStatus->value : $previousStatus;
        $to = $deal->status instanceof DealStatus ? $deal->status->value : $deal->status;

        if ($from !== $to) {
            RecordsActivity::core('deal.stage_changed', $deal, [
                'from' => $from,
                'to' => $to,
            ]);

            if ($deal->status === DealStatus::ClosedWon) {
                RecordsActivity::core('deal.won', $deal, ['status' => $to]);
            } elseif ($deal->status === DealStatus::ClosedLost) {
                RecordsActivity::core('deal.lost', $deal, ['status' => $to]);
            }
        }

        return $this->success(
            DealCardResource::make($deal),
            'Deal status updated successfully.'
        );
    }

    public function destroy(Deal $deal): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value, $deal);

        $deal->delete();

        return $this->noContent('Deal deleted successfully.');
    }

    public function options(Request $request): JsonResponse
    {
        Gate::authorize(Permission::DealManage->value);

        $request->validate([
            'search' => ['nullable', 'string'],
        ]);

        $search = $request->string('search')->trim()->value();

        $query = Deal::query()->with('contact')->latest()->limit(20);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('contact', function ($contactQuery) use ($search) {
                    $contactQuery->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%");
                })->orWhere('id', 'like', "%{$search}%");
            });
        }

        $options = $query->get()->map(fn (Deal $deal) => [
            'value' => $deal->id,
            'label' => 'Deal #' . $deal->id . ($deal->contact
                ? ' — ' . trim("{$deal->contact->first_name} {$deal->contact->last_name}")
                : ''),
        ]);

        return $this->success($options, 'Deal options retrieved successfully.');
    }
}
