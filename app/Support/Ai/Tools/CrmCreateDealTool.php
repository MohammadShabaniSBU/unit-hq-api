<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Enums\LogChannel;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\VerificationLevel;

final class CrmCreateDealTool implements AgentTool
{
    public function key(): string
    {
        return 'crm.create_deal';
    }

    public function description(): string
    {
        return 'Open a new pipeline deal for a contact.';
    }

    public function schema(): array
    {
        return [
            'contact_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Contact id',
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Site id',
            ],
            'unit_class_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Desired unit class id',
            ],
            'notes' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional note on the deal',
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

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $contactId = (int) $arguments['contact_id'];
        $contact = Contact::query()->find($contactId);
        if ($contact === null) {
            return ToolResult::notFound('Contact not found.');
        }

        if ($principal->contactId !== null && ! $principal->ownsContact($contactId)) {
            return ToolResult::denied(
                ToolDeniedReason::Ownership,
                'Argument [contact_id] does not belong to this principal.',
            );
        }

        if ($principal->contactId === null && $contact->source !== ContactSource::AiAgent) {
            return ToolResult::denied(
                ToolDeniedReason::Ownership,
                'Anonymous writes may only attach to agent-sourced contacts.',
            );
        }

        $deal = Deal::query()->create([
            'contact_id' => $contact->id,
            'site_id' => isset($arguments['site_id']) ? (int) $arguments['site_id'] : $principal->siteId,
            'desired_unit_class_id' => isset($arguments['unit_class_id']) ? (int) $arguments['unit_class_id'] : null,
            'status' => DealStatus::New,
        ]);

        $notes = isset($arguments['notes']) ? trim((string) $arguments['notes']) : '';
        $employeeId = AgentWriteAttribution::employeeId($ctx);
        if ($notes !== '' && $employeeId !== null) {
            $deal->notes()->create([
                'employee_id' => $employeeId,
                'content' => $notes,
            ]);
        }

        AgentWriteAttribution::log(LogChannel::Crm, 'deal.created', $deal, $ctx, [
            'contact_id' => $contact->id,
        ]);

        return ToolResult::ok(
            [
                'deal_id' => $deal->id,
                'contact_id' => $contact->id,
                'status' => DealStatus::New->value,
            ],
            "Opened deal {$deal->id} for contact {$contact->id}.",
            (new FactBag)->identifier((string) $deal->id)->number($deal->id)->number($contact->id),
            resultType: 'deal',
            resultId: $deal->id,
        );
    }
}
