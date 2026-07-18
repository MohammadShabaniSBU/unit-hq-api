<?php

namespace App\Http\Controllers;

use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactRecordStatus;
use App\Enums\ContactSource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query()->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->value();
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Contact $contact) => ContactResource::make($contact)),
            'Contacts retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'           => ['required', 'string', 'max:255'],
            'last_name'            => ['required', 'string', 'max:255'],
            'email'                => ['nullable', 'email', 'max:255'],
            'company'              => ['nullable', 'string', 'max:255'],
            'contact_status'       => ['nullable', Rule::enum(ContactRecordStatus::class)],
            'canonical_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'source'               => ['nullable', Rule::enum(ContactSource::class)],
            'source_detail'        => ['nullable', 'string', 'max:255'],
            'assigned_to'          => ['nullable', 'integer', 'exists:employees,id'],
            'created_by'           => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $contact = Contact::query()->create($validated);

        return $this->created(
            ContactResource::make($contact),
            'Contact created successfully.'
        );
    }

    public function show(Contact $contact): JsonResponse
    {
        $contact->load([
            'channels',
            'addresses.country',
            'deals.desiredUnitClass',
            'deals.offers',
            'contracts.items.item',
            'reservations.unit.site',
            'reservations.unit.unitClass',
            'tasks',
            'notes',
        ]);

        return $this->success(
            ContactResource::make($contact),
            'Contact retrieved successfully.'
        );
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'first_name'           => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'            => ['sometimes', 'required', 'string', 'max:255'],
            'email'                => ['sometimes', 'nullable', 'email', 'max:255'],
            'company'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'               => ['sometimes', 'nullable', Rule::enum(ContactLifecycleStatus::class)],
            'contact_status'       => ['sometimes', 'nullable', Rule::enum(ContactRecordStatus::class)],
            'canonical_contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'source'               => ['sometimes', 'nullable', Rule::enum(ContactSource::class)],
            'source_detail'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'assigned_to'          => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $contact->update($validated);

        return $this->success(
            ContactResource::make($contact->fresh()),
            'Contact updated successfully.'
        );
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();

        return $this->noContent('Contact deleted successfully.');
    }

    public function transactions(Contact $contact): JsonResponse
    {
        $contractScope = fn ($query) => $query->where('contact_id', $contact->id);

        $invoices = Invoice::query()
            ->whereHas('contract', $contractScope)
            ->with(['charges', 'contract.items.item'])
            ->orderByDesc('billing_period_start')
            ->get();

        $payments = Payment::query()
            ->whereHas('contract', $contractScope)
            ->with(['allocations', 'contract.items.item'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'invoices' => InvoiceResource::collection($invoices)->resolve(),
            'payments' => PaymentResource::collection($payments)->resolve(),
        ], 'Contact transactions retrieved successfully.');
    }

    public function options(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['required', 'string', 'min:2'],
        ]);

        $search = $request->string('search')->trim()->value();

        $options = Contact::query()
            ->where('first_name', 'ilike', "%{$search}%")
            ->orWhere('last_name', 'ilike', "%{$search}%")
            ->orWhere('email', 'ilike', "%{$search}%")
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Contact $contact) => [
                'value' => $contact->id,
                'label' => trim("{$contact->first_name} {$contact->last_name}"),
            ]);

        return $this->success($options, 'Contact options retrieved successfully.');
    }
}
