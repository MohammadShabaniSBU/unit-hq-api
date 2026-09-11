<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\LogChannel;
use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;

final class CrmUpdateContactTool implements AgentTool
{
    public function key(): string
    {
        return 'crm.update_contact';
    }

    public function description(): string
    {
        return 'Update the first or last name on the contact this conversation already belongs to. Do not create a new contact.';
    }

    public function schema(): array
    {
        return [
            'first_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Updated first name',
            ],
            'last_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Updated last name',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function retainInSummary(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $first = isset($arguments['first_name']) ? trim((string) $arguments['first_name']) : '';
        $last = isset($arguments['last_name']) ? trim((string) $arguments['last_name']) : '';
        if ($first === '' && $last === '') {
            return ToolResult::fail(ToolError::invalidArguments(
                'A first name or last name is required.',
                ['hint' => 'pass first_name and/or last_name'],
            ));
        }

        $contactId = $ctx?->conversation?->contact_id ?? $principal->contactId;
        if ($contactId === null) {
            return $this->missingContact();
        }

        $contact = Contact::query()->find($contactId);
        if ($contact === null) {
            return $this->missingContact();
        }

        if ($first !== '') {
            $contact->first_name = $first;
        }
        if ($last !== '') {
            $contact->last_name = $last;
        }
        $contact->save();

        AgentWriteAttribution::log(LogChannel::Crm, 'contact.updated', $contact, $ctx);

        return ToolResult::ok(
            [
                'contact_id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
            ],
            "Updated the contact name to {$contact->first_name} {$contact->last_name}.",
            (new FactBag)->identifier((string) $contact->id)->number($contact->id),
            resultType: 'contact',
            resultId: $contact->id,
            entities: [EntityRef::contact($contact)],
        );
    }

    private function missingContact(): ToolResult
    {
        return ToolResult::fail(ToolError::unavailable(
            'A contact is required before the name can be updated.',
            [
                'tool' => 'crm.create_contact',
                'hint' => 'create a contact first, then update the name',
            ],
        ));
    }
}
