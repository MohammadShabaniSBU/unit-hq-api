<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DelinquencyStepResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delinquency_id' => $this->delinquency_id,
            'policy_step_id' => $this->policy_step_id,
            'action' => $this->action instanceof \BackedEnum
                ? $this->action->value
                : $this->action,
            'executed_on' => $this->date($this->executed_on),
            'trigger' => $this->trigger instanceof \BackedEnum
                ? $this->trigger->value
                : $this->trigger,
            'detail' => $this->detail,
            'created_by' => $this->when(
                $this->relationLoaded('createdBy') && $this->createdBy !== null,
                fn () => [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ]
            ),
            'charge' => $this->when(
                $this->relationLoaded('charge') && $this->charge !== null,
                fn () => [
                    'id' => $this->charge->id,
                    'charge_type' => $this->charge->charge_type instanceof \BackedEnum
                        ? $this->charge->charge_type->value
                        : $this->charge->charge_type,
                    'amount' => $this->charge->amount,
                    'net_amount' => $this->charge->net_amount,
                    'tax_amount' => $this->charge->tax_amount,
                    'currency' => $this->charge->currency,
                    'due_date' => $this->date($this->charge->due_date),
                    'description' => $this->charge->description,
                ]
            ),
            'unit_hold' => $this->when(
                $this->relationLoaded('unitHold') && $this->unitHold !== null,
                fn () => UnitHoldResource::make($this->unitHold)
            ),
            'contract_notice' => $this->when(
                $this->relationLoaded('contractNotice') && $this->contractNotice !== null,
                fn () => [
                    'id' => $this->contractNotice->id,
                    'notice_type' => $this->contractNotice->notice_type instanceof \BackedEnum
                        ? $this->contractNotice->notice_type->value
                        : $this->contractNotice->notice_type,
                    'sent_at' => $this->datetime($this->contractNotice->sent_at),
                    'sent_channel' => $this->contractNotice->sent_channel,
                    'sent_to' => $this->contractNotice->sent_to,
                    'effective_date' => $this->date($this->contractNotice->effective_date),
                    'required_by' => $this->date($this->contractNotice->required_by),
                ]
            ),
            'task' => $this->when(
                $this->relationLoaded('task') && $this->task !== null,
                fn () => [
                    'id' => $this->task->id,
                    'title' => $this->task->title,
                    'status' => $this->task->status,
                    'priority' => $this->task->priority,
                ]
            ),
            'created_at' => $this->datetime($this->created_at),
        ];
    }
}
