<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Contact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateContact implements Tool, Approvable
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Create a new contact in the CRM with name, email, and other details.';
    }

    public function handle(Request $request): Stringable|string
    {
        $contact = Contact::query()->create([
            'first_name' => $request['first_name'],
            'last_name' => $request['last_name'] ?? '',
            'email' => $request['email'] ?? null,
            'company' => $request['company'] ?? null,
            'source' => $request['source'] ?? null,
            'source_detail' => $request['source_detail'] ?? null,
        ]);

        return json_encode([
            'success' => true,
            'message' => "Contact '{$contact->first_name} {$contact->last_name}' created successfully.",
            'contact_id' => $contact->id,
            'contact_name' => "{$contact->first_name} {$contact->last_name}",
            'email' => $contact->email,
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
            'email' => $schema->string()
                ->description('Email address of the contact')
                ->nullable(),
            'company' => $schema->string()
                ->description('Company name')
                ->nullable(),
            'source' => $schema->string()
                ->description('Source where contact came from (e.g., social_media, google, web_form, organic)')
                ->enum(['social_media', 'google', 'meta', 'organic', 'offline', 'walk_ins', 'calls', 'emailing', 'referrals', 'aircall_paid', 'email_conversations', 'website', 'web_form', 'import', 'other'])
                ->nullable(),
            'source_detail' => $schema->string()
                ->description('Additional details about the source')
                ->nullable(),
        ];
    }
}
