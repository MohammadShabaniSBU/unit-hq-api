<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CommsTriage;
use App\Models\Contact;
use App\Support\Communications\ProviderRegistry;
use App\Support\Communications\TriageResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CommsTriageController extends Controller
{
    public function attach(Request $request, CommsTriage $commsTriage, ProviderRegistry $registry): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
        ]);

        $contact = Contact::query()->findOrFail($validated['contact_id']);

        try {
            $message = TriageResolver::attach($commsTriage, $contact, $registry);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        return $this->success([
            'triage_id' => $commsTriage->id,
            'message_id' => $message->id,
            'contact_id' => $contact->id,
            'status' => 'resolved',
        ], 'Triage attached.');
    }

    public function createAndAttach(Request $request, CommsTriage $commsTriage, ProviderRegistry $registry): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
        ]);

        try {
            $message = TriageResolver::createAndAttach($commsTriage, $registry, $validated);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        $commsTriage->refresh();

        return $this->success([
            'triage_id' => $commsTriage->id,
            'message_id' => $message->id,
            'contact_id' => $commsTriage->resolved_contact_id,
            'status' => 'resolved',
        ], 'Contact created and triage attached.');
    }

    public function discard(CommsTriage $commsTriage): JsonResponse
    {
        try {
            TriageResolver::discard($commsTriage);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        return $this->success([
            'triage_id' => $commsTriage->id,
            'status' => 'discarded',
        ], 'Triage discarded.');
    }
}
