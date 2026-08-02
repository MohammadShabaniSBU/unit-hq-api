<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Employee;
use App\Support\Communications\CallAvailability;
use App\Support\Communications\CallDialer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Click-to-dial + per-employee availability (S12-00).
 */
class CallController extends Controller
{
    public function dial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'to_number' => ['nullable', 'string', 'max:32'],
            'context' => ['nullable', 'array'],
            'context.type' => ['required_with:context', 'string', 'in:thread,delinquency,task,contact'],
            'context.id' => ['nullable', 'integer'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();
        $contact = Contact::query()->findOrFail((int) $validated['contact_id']);

        $result = CallDialer::dial(
            $employee,
            $contact,
            $validated['to_number'] ?? null,
            $validated['context'] ?? null,
        );

        $intent = $result['intent'];

        return $this->success([
            'id' => $intent->id,
            'contact_id' => $intent->contact_id,
            'to_number' => $intent->to_number,
            'context_type' => $intent->context_type,
            'context_id' => $intent->context_id,
            'aircall_call_id' => $intent->aircall_call_id,
            'status' => $intent->status,
            'message_id' => $intent->message_id,
        ], 'Call requested. Pick up your Aircall device to continue.');
    }

    public function availability(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        return $this->success(
            CallAvailability::forEmployee($employee),
            'Call availability retrieved successfully.'
        );
    }
}
