<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactRecordStatus;
use App\Enums\TaxIdType;
use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Resources\BillingPeriodResource;
use App\Http\Resources\ContactCardResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\PaymentResource;
use App\Models\BillingPeriod;
use App\Models\Contact;
use App\Models\Payment;
use App\Support\Fiscal\TaxId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class ContactController extends Controller
{
    use AppliesPortalSiteFilter;
    use SearchesWithFilters;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $query = Contact::query()->visibleTo($employee, Permission::ContactView)->latest();
        $this->applyPortalSiteFilter($query, $request, Contact::class, Permission::ContactView);

        if ($request->filled('search')) {
            $query->search($request->string('search')->trim()->value());
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

    public function filterSchema(): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        return $this->respondFilterSchema(AttributeEntityType::Contact);
    }

    public function search(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $query = Contact::query()->visibleTo($employee, Permission::ContactView);
        $this->applyPortalSiteFilter($query, $request, Contact::class, Permission::ContactView);

        return $this->searchWithFilters(
            $request,
            AttributeEntityType::Contact,
            $query,
            fn (Contact $contact) => ContactResource::make($contact),
            'Contacts retrieved successfully.',
            function ($query, Request $request): void {
                if ($request->filled('assigned_to')) {
                    $query->where('assigned_to', $request->integer('assigned_to'));
                }
            },
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value);

        $validated = $request->validate([
            'first_name'           => ['required', 'string', 'max:255'],
            'last_name'            => ['required', 'string', 'max:255'],
            'email'                => ['nullable', 'email', 'max:255'],
            'company'              => ['nullable', 'string', 'max:255'],
            'locale'               => ['nullable', 'string', 'in:en,es,fr'],
            ...$this->fiscalValidationRules(sometimes: false),
            'contact_status'       => ['nullable', Rule::enum(ContactRecordStatus::class)],
            'canonical_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'assigned_to'          => ['nullable', 'integer', 'exists:employees,id'],
            'created_by'           => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $validated = $this->normalizeFiscalFields($validated);

        $contact = Contact::query()->create($validated);

        return $this->created(
            ContactResource::make($contact),
            'Contact created successfully.'
        );
    }

    public function show(Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value, $contact);

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
        Gate::authorize(Permission::ContactManage->value, $contact);

        $validated = $request->validate([
            'first_name'           => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'            => ['sometimes', 'required', 'string', 'max:255'],
            'email'                => ['sometimes', 'nullable', 'email', 'max:255'],
            'company'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale'               => ['sometimes', 'nullable', 'string', 'in:en,es,fr'],
            ...$this->fiscalValidationRules(sometimes: true),
            'status'               => ['sometimes', 'nullable', Rule::enum(ContactLifecycleStatus::class)],
            'contact_status'       => ['sometimes', 'nullable', Rule::enum(ContactRecordStatus::class)],
            'canonical_contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'assigned_to'          => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $validated = $this->normalizeFiscalFields($validated);

        $contact->update($validated);

        return $this->success(
            ContactResource::make($contact->fresh()),
            'Contact updated successfully.'
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function fiscalValidationRules(bool $sometimes): array
    {
        $prefix = $sometimes ? ['sometimes'] : [];

        return [
            'billing_name' => [...$prefix, 'nullable', 'string', 'max:255'],
            'tax_id' => [...$prefix, 'nullable', 'string', 'max:64'],
            'tax_id_type' => [
                ...$prefix,
                'nullable',
                Rule::enum(TaxIdType::class),
                'required_with:tax_id',
            ],
            'billing_address_line1' => [...$prefix, 'nullable', 'string', 'max:255'],
            'billing_address_line2' => [...$prefix, 'nullable', 'string', 'max:255'],
            'billing_city' => [...$prefix, 'nullable', 'string', 'max:128'],
            'billing_postal_code' => [...$prefix, 'nullable', 'string', 'max:32'],
            'billing_country_code' => [...$prefix, 'nullable', 'string', 'size:2'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeFiscalFields(array $validated): array
    {
        if (array_key_exists('billing_country_code', $validated) && is_string($validated['billing_country_code'])) {
            $validated['billing_country_code'] = strtoupper($validated['billing_country_code']);
        }

        if (! array_key_exists('tax_id', $validated) || $validated['tax_id'] === null || $validated['tax_id'] === '') {
            return $validated;
        }

        $type = $validated['tax_id_type'] ?? null;
        $typeValue = $type instanceof TaxIdType ? $type->value : (string) $type;

        $normalized = TaxId::normalize((string) $validated['tax_id']);
        $validated['tax_id'] = $normalized;

        if ($typeValue === '' || ! TaxId::validate($normalized, $typeValue)) {
            throw ValidationException::withMessages([
                'tax_id' => [__('errors.contacts.invalid_tax_id')],
            ]);
        }

        return $validated;
    }

    public function updateStatus(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ContactLifecycleStatus::class)],
        ]);

        $contact->update(['status' => $validated['status']]);

        return $this->success(
            ContactCardResource::make($contact->fresh()->loadCount('deals')),
            'Contact status updated successfully.'
        );
    }

    public function destroy(Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::ContactManage->value, $contact);

        $contact->delete();

        return $this->noContent('Contact deleted successfully.');
    }

    public function transactions(Contact $contact): JsonResponse
    {
        Gate::authorize(Permission::PaymentView->value, $contact);

        $contractScope = fn ($query) => $query->where('contact_id', $contact->id);

        $billingPeriods = BillingPeriod::query()
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
            'billing_periods' => BillingPeriodResource::collection($billingPeriods)->resolve(),
            'payments' => PaymentResource::collection($payments)->resolve(),
        ], 'Contact transactions retrieved successfully.');
    }

    public function options(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var \App\Models\Employee $employee */
        $employee = $request->user();

        $request->validate([
            'search' => ['required', 'string', 'min:2'],
        ]);

        $search = $request->string('search')->trim()->value();

        $options = Contact::query()
            ->visibleTo($employee, Permission::ContactView)
            ->where(function ($q) use ($search): void {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            })
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
