<?php

namespace App\Http\Controllers;

use App\Enums\ContactChannelType;
use App\Http\Resources\ContactChannelResource;
use App\Models\Contact;
use App\Models\ContactChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContactChannelController extends Controller
{
    public function store(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        $validated = $request->validate([
            'type'       => ['required', Rule::enum(ContactChannelType::class)],
            'value'      => ['required', 'string', 'max:255'],
            'label'      => ['nullable', 'string', 'max:255'],
            'is_primary' => ['boolean'],
            'opted_in'   => ['boolean'],
        ]);

        $channel = $contact->channels()->create($validated);

        return $this->created(
            ContactChannelResource::make($channel),
            'Contact channel created successfully.'
        );
    }

    public function update(Request $request, Contact $contact, ContactChannel $channel): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        if ($channel->contact_id !== $contact->id) {
            return $this->notFound('Contact channel not found.');
        }

        $validated = $request->validate([
            'type'       => ['sometimes', 'required', Rule::enum(ContactChannelType::class)],
            'value'      => ['sometimes', 'required', 'string', 'max:255'],
            'label'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'opted_in'   => ['sometimes', 'boolean'],
        ]);

        $channel->update($validated);

        return $this->success(
            ContactChannelResource::make($channel->fresh()),
            'Contact channel updated successfully.'
        );
    }

    public function destroy(Contact $contact, ContactChannel $channel): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        if ($channel->contact_id !== $contact->id) {
            return $this->notFound('Contact channel not found.');
        }

        $channel->delete();

        return $this->noContent('Contact channel deleted successfully.');
    }
}
