<?php

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contract::query()
            ->with(['items.item', 'contact', 'reservation'])
            ->latest();

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->integer('contact_id'));
        }

        if ($request->filled('deal_id')) {
            $query->where('deal_id', $request->integer('deal_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Contract $contract) => ContractResource::make($contract)),
            'Contracts retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id'     => ['required', 'integer', 'exists:contacts,id'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'deal_id'        => ['nullable', 'integer', 'exists:deals,id'],
            'start_date'     => ['required', 'date'],
            'end_date'       => ['nullable', 'date', 'after:start_date'],
            'status'         => ['nullable', Rule::enum(ContractStatus::class)],
            'signed_at'      => ['nullable', 'date'],
            'items'          => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', 'string', Rule::in(['unit', 'insurance'])],
            'items.*.item_id'   => ['required', 'integer'],
            'items.*.rate'      => ['required', 'numeric', 'min:0'],
        ]);

        $contract = DB::transaction(function () use ($validated) {
            $contract = Contract::query()->create([
                'contact_id'     => $validated['contact_id'],
                'reservation_id' => $validated['reservation_id'] ?? null,
                'deal_id'        => $validated['deal_id'] ?? null,
                'start_date'     => $validated['start_date'],
                'end_date'       => $validated['end_date'] ?? null,
                'status'         => $validated['status'] ?? ContractStatus::Active->value,
                'signed_at'      => $validated['signed_at'] ?? now(),
            ]);

            foreach ($validated['items'] as $itemData) {
                $contract->items()->create([
                    'item_type' => $itemData['item_type'],
                    'item_id'   => $itemData['item_id'],
                    'rate'      => $itemData['rate'],
                ]);
            }

            return $contract;
        });

        return $this->created(
            ContractResource::make($contract->load(['items.item', 'contact', 'reservation'])),
            'Contract created successfully.'
        );
    }

    public function show(Contract $contract): JsonResponse
    {
        $contract->load(['items.item', 'contact', 'reservation', 'deal', 'notes']);

        return $this->success(
            ContractResource::make($contract),
            'Contract retrieved successfully.'
        );
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date'   => ['sometimes', 'nullable', 'date'],
            'status'     => ['sometimes', 'required', Rule::enum(ContractStatus::class)],
            'signed_at'  => ['sometimes', 'required', 'date'],
        ]);

        $contract->update($validated);

        return $this->success(
            ContractResource::make($contract->fresh()->load(['items.item', 'contact', 'reservation'])),
            'Contract updated successfully.'
        );
    }

    public function destroy(Contract $contract): JsonResponse
    {
        $contract->delete();

        return $this->noContent('Contract deleted successfully.');
    }
}
