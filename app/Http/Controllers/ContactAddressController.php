<?php

namespace App\Http\Controllers;

use App\Enums\ContactAddressType;
use App\Http\Resources\ContactAddressResource;
use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContactAddressController extends Controller
{
    public function store(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        $validated = $request->validate([
            'type'        => ['required', Rule::enum(ContactAddressType::class)],
            'line1'       => ['nullable', 'string', 'max:255'],
            'line2'       => ['nullable', 'string', 'max:255'],
            'city'        => ['nullable', 'string', 'max:255'],
            'state'       => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'country_id'  => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'label'       => ['nullable', 'string', 'max:255'],
            'is_primary'  => ['boolean'],
        ]);

        $address = $contact->addresses()->create($validated);

        return $this->created(
            ContactAddressResource::make($address->load('country')),
            'Contact address created successfully.'
        );
    }

    public function update(Request $request, Contact $contact, ContactAddress $address): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        if ($address->contact_id !== $contact->id) {
            return $this->notFound('Contact address not found.');
        }

        $validated = $request->validate([
            'type'        => ['sometimes', 'required', Rule::enum(ContactAddressType::class)],
            'line1'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'line2'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'city'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'state'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country_id'  => ['sometimes', 'nullable', 'integer', Rule::exists('countries', 'id')],
            'label'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary'  => ['sometimes', 'boolean'],
        ]);

        $address->update($validated);

        return $this->success(
            ContactAddressResource::make($address->fresh()->load('country')),
            'Contact address updated successfully.'
        );
    }

    public function destroy(Contact $contact, ContactAddress $address): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        if ($address->contact_id !== $contact->id) {
            return $this->notFound('Contact address not found.');
        }

        $address->delete();

        return $this->noContent('Contact address deleted successfully.');
    }
}
