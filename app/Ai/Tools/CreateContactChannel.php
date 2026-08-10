<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\ContactChannelType;
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

class CreateContactChannel implements Tool, Approvable
{
    use InteractsWithApprovals;

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Add a communication channel (email, phone, SMS, or WhatsApp) to a contact.';
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
                'error' => 'You do not have permission to add a channel to this contact.',
            ]);
        }

        $channel = $contact->channels()->create([
            'type' => $request['type'],
            'value' => $request['value'],
            'label' => $request['label'] ?? null,
            'is_primary' => $request['is_primary'] ?? false,
            'opted_in' => $request['opted_in'] ?? false,
        ]);

        return json_encode([
            'success' => true,
            'message' => 'Contact channel added successfully.',
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->integer()
                ->description('ID of the contact to add the channel to')
                ->required(),
            'type' => $schema->string()
                ->description('Channel type')
                ->enum(array_map(fn (ContactChannelType $type) => $type->value, ContactChannelType::cases()))
                ->required(),
            'value' => $schema->string()
                ->description('The channel value (e.g. email address or phone number)')
                ->required(),
            'label' => $schema->string()->description('Custom label for this channel')->nullable(),
            'is_primary' => $schema->boolean()->description('Whether this is the primary channel of its type')->nullable(),
            'opted_in' => $schema->boolean()->description('Whether the contact has opted in to communications on this channel')->nullable(),
        ];
    }
}
