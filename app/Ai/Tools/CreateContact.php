<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Auth\Permission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateContact implements Tool, Approvable
{
    use InteractsWithApprovals;

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Create a new contact in the CRM with name, optional email/phone, and a required site association.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->employee->allowsPermission(Permission::ContactManage)) {
            return json_encode([
                'success' => false,
                'error' => 'You do not have permission to create contacts.',
            ]);
        }

        $siteId = (int) $request['site_id'];
        if (! Site::query()->whereKey($siteId)->exists()) {
            return json_encode([
                'success' => false,
                'error' => 'The selected site does not exist.',
            ]);
        }

        $phone = isset($request['phone']) ? trim((string) $request['phone']) : '';

        $contact = DB::transaction(function () use ($request, $siteId, $phone): Contact {
            $contact = Contact::query()->create([
                'first_name' => $request['first_name'],
                'last_name' => $request['last_name'] ?? '',
                'email' => $request['email'] ?? null,
                'company' => $request['company'] ?? null,
            ]);

            $contact->sites()->attach($siteId);

            if ($phone !== '') {
                $contact->channels()->create([
                    'type' => ContactChannelType::Phone,
                    'value' => $phone,
                    'is_primary' => true,
                ]);
            }

            return $contact;
        });

        return json_encode([
            'success' => true,
            'message' => "Contact '{$contact->first_name} {$contact->last_name}' created successfully.",
            'contact_id' => $contact->id,
            'contact_name' => "{$contact->first_name} {$contact->last_name}",
            'email' => $contact->email,
            'site_id' => $siteId,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'first_name' => $schema->string()
                ->description('First name of the contact')
                ->required(),
            'last_name' => $schema->string()
                ->description('Last name of the contact')
                ->required(),
            'site_id' => $schema->integer()
                ->description('Site ID to associate with this contact')
                ->required(),
            'email' => $schema->string()
                ->description('Email address of the contact')
                ->nullable(),
            'phone' => $schema->string()
                ->description('Phone number of the contact')
                ->nullable(),
            'company' => $schema->string()
                ->description('Company name')
                ->nullable(),
        ];
    }
}
