<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Employee;
use App\Support\Contracts\ScheduleRateChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContractRateChangeController extends Controller
{
    public function store(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::ContractRateChange->value, $contract);

        $validated = $request->validate([
            'contract_item_id' => ['required', 'integer', 'exists:contract_items,id'],
            'new_amount' => ['required', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
            'acknowledge_short_notice' => ['sometimes', 'boolean'],
            'short_notice_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $employee = $user instanceof Employee ? $user : null;

        $result = ScheduleRateChange::run(
            contract: $contract,
            contractItemId: (int) $validated['contract_item_id'],
            newAmount: (string) $validated['new_amount'],
            effectiveDate: (string) $validated['effective_date'],
            createdBy: $employee,
            acknowledgeShortNotice: (bool) ($validated['acknowledge_short_notice'] ?? false),
            shortNoticeReason: $validated['short_notice_reason'] ?? null,
        );

        $notice = $result['notice'];

        return $this->created([
            'item' => $this->formatItem($result['item']),
            'previous_item' => $this->formatItem($result['previous']),
            'notice' => [
                'id' => $notice->id,
                'notice_type' => $notice->notice_type->value,
                'effective_date' => $notice->effective_date?->toDateString(),
                'required_by' => $notice->required_by?->toDateString(),
                'short_notice_reason' => $notice->short_notice_reason,
                'contract_item_id' => $notice->contract_item_id,
                'sent_at' => null,
            ],
        ], 'Rate change scheduled successfully.');
    }

    /** @return array<string, mixed> */
    private function formatItem(ContractItem $item): array
    {
        $item->loadMissing('price');

        return [
            'id' => $item->id,
            'item_type' => $item->item_type,
            'item_id' => $item->item_id,
            'amount' => $item->price?->amount,
            'currency' => $item->price?->currency,
            'price_id' => $item->price_id,
            'effective_from' => $item->effective_from?->toDateString(),
            'effective_to' => $item->effective_to?->toDateString(),
            'supersedes_id' => $item->supersedes_id,
            'change_reason' => $item->change_reason?->value,
        ];
    }
}
