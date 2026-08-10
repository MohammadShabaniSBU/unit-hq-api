<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\ContactAddressType;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Auth\Permission;
use App\Support\Auth\SubjectSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateContactAddress implements Tool, Approvable
{
    use InteractsWithApprovals;

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Add a physical address to a contact.';
    }

    public function handle(Request $request): Stringable|string
    {
        $contact = Contact::query()->find($request['contact_id']);

        if ($contact === null) {
            return json_encode([
                'success' => false,
                'error' => 'No contact found with that ID.',
            ]);
        }

        if (! $this->employee->allowsPermission(Permission::ContactManage, SubjectSite::for($contact))) {
            return json_encode([
                'success' => false,
                'error' => 'You do not have permission to add an address to this contact.',
            ]);
        }

        $address = $contact->addresses()->create([
            'type' => $request['type'],
            'line1' => $request['line1'] ?? null,
            'line2' => $request['line2'] ?? null,
            'city' => $request['city'] ?? null,
            'state' => $request['state'] ?? null,
            'postal_code' => $request['postal_code'] ?? null,
            'country_id' => $request['country_id'] ?? null,
            'label' => $request['label'] ?? null,
            'is_primary' => $request['is_primary'] ?? false,
        ]);

        return json_encode([
            'success' => true,
            'message' => 'Contact address added successfully.',
            'address_id' => $address->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->integer()
                ->description('ID of the contact to add the address to')
                ->required(),
            'type' => $schema->string()
                ->description('Address type')
                ->enum(array_map(fn (ContactAddressType $type) => $type->value, ContactAddressType::cases()))
                ->required(),
            'line1' => $schema->string()->description('Address line 1')->nullable(),
            'line2' => $schema->string()->description('Address line 2')->nullable(),
            'city' => $schema->string()->description('City')->nullable(),
            'state' => $schema->string()->description('State/province')->nullable(),
            'postal_code' => $schema->string()->description('Postal/ZIP code')->nullable(),
            'country_id' => $schema->integer()->description('ID of the country')->nullable(),
            'label' => $schema->string()->description('Custom label for this address')->nullable(),
            'is_primary' => $schema->boolean()->description('Whether this is the primary address')->nullable(),
        ];
    }
}
