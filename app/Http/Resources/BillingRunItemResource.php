<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AutopayAttemptStatus;
use App\Models\AutopayAttempt;
use App\Models\BillingRunItem;
use Illuminate\Http\Request;

class BillingRunItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BillingRunItem $item */
        $item = $this->resource;

        $contract = $item->relationLoaded('contract') ? $item->contract : null;
        $contact = $contract?->relationLoaded('contact') ? $contract->contact : null;
        $unitItem = $contract?->relationLoaded('unitItem') ? $contract->unitItem : null;
        $unit = $unitItem?->relationLoaded('item') ? $unitItem->item : null;

        $contactName = null;
        if ($contact !== null) {
            $contactName = trim($contact->first_name.' '.$contact->last_name);
            if ($contactName === '') {
                $contactName = null;
            }
        }

        $unitNumber = is_object($unit) && isset($unit->unit_number)
            ? (string) $unit->unit_number
            : null;

        return [
            'id' => $item->id,
            'billing_run_id' => $item->billing_run_id,
            'contract_id' => $item->contract_id,
            'outcome' => $item->outcome instanceof \BackedEnum
                ? $item->outcome->value
                : (string) $item->outcome,
            'periods_billed' => $item->periods_billed,
            'detail' => $item->detail,
            'error_message' => $item->error_message,
            'invoice_ids' => $item->invoice_ids ?? [],
            'amount_total' => $item->amount_total !== null ? (string) $item->amount_total : null,
            'currency' => $item->currency,
            'autopay' => $this->autopayStatus($item, $contract),
            'contract' => $contract !== null ? [
                'id' => $contract->id,
                'contact_id' => $contract->contact_id,
                'contact_name' => $contactName,
                'unit_number' => $unitNumber,
            ] : null,
            'created_at' => $this->datetime($item->created_at),
        ];
    }

    /**
     * @return 'collected'|'failed'|'pending'|'off'
     */
    private function autopayStatus(BillingRunItem $item, mixed $contract): string
    {
        /** @var AutopayAttempt|null $attempt */
        $attempt = null;

        if ($contract !== null && $contract->relationLoaded('autopayAttempts')) {
            $attempt = $contract->autopayAttempts
                ->first(fn (AutopayAttempt $a): bool => (int) $a->billing_run_id === (int) $item->billing_run_id);
        }

        if ($attempt === null) {
            return 'off';
        }

        return match ($attempt->status) {
            AutopayAttemptStatus::Succeeded => 'collected',
            AutopayAttemptStatus::Failed => 'failed',
            AutopayAttemptStatus::Pending => 'pending',
            default => 'off',
        };
    }
}
