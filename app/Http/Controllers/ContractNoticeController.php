<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContractNotice;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Mark-sent for contract notices (S02-04 / S07-04). Send fields only — append-only otherwise.
 */
class ContractNoticeController extends Controller
{
    public function markSent(Request $request, ContractNotice $contractNotice): JsonResponse
    {
        Gate::authorize(Permission::DelinquencyAct->value, $contractNotice);

        $validated = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms', 'post', 'in_person'])],
            'sent_at' => ['sometimes', 'nullable', 'date'],
            'sent_to' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($contractNotice->sent_at !== null) {
            return $this->error(__('errors.delinquency.notice_already_sent'), [], 422);
        }

        /** @var Employee|null $employee */
        $employee = $request->user();

        $sentAt = isset($validated['sent_at'])
            ? Carbon::parse($validated['sent_at'])
            : now();

        $contractNotice->forceFill([
            'sent_at' => $sentAt,
            'sent_channel' => $validated['channel'],
            'sent_to' => $validated['sent_to'] ?? $contractNotice->sent_to,
            'created_by' => $contractNotice->created_by ?? $employee?->id,
        ])->save();

        return $this->success([
            'id' => $contractNotice->id,
            'notice_type' => $contractNotice->notice_type instanceof \BackedEnum
                ? $contractNotice->notice_type->value
                : $contractNotice->notice_type,
            'sent_at' => $contractNotice->sent_at?->toIso8601String(),
            'sent_channel' => $contractNotice->sent_channel,
            'sent_to' => $contractNotice->sent_to,
        ], 'Notice marked as sent.');
    }
}
