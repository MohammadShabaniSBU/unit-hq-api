<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\InteractionResource;
use App\Models\Contact;
use App\Models\Interaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContactInteractionController extends Controller
{
    public function index(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value, $contact);

        $interactions = $contact->interactions()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->through(fn (Interaction $interaction) => InteractionResource::make($interaction));

        return $this->paginated(
            $interactions,
            'Interactions retrieved successfully.'
        );
    }

    public function store(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        $validated = $request->validate([
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'channel' => ['required', 'string', Rule::in(Interaction::CHANNELS)],
            'direction' => ['required', 'string', Rule::in(Interaction::DIRECTIONS)],
            'occurred_at' => ['nullable', 'date'],
            'content' => ['nullable', 'string'],
            'summary' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (isset($validated['deal_id'])) {
            $dealBelongsToContact = $contact->deals()
                ->whereKey($validated['deal_id'])
                ->exists();

            if (! $dealBelongsToContact) {
                return $this->error('Deal does not belong to this contact.', [
                    'deal_id' => ['The selected deal is invalid for this contact.'],
                ], 422);
            }
        }

        $interaction = $contact->interactions()->create([
            ...$validated,
            'occurred_at' => $validated['occurred_at'] ?? now(),
        ]);

        return $this->created(
            InteractionResource::make($interaction),
            'Interaction created successfully.'
        );
    }
}
