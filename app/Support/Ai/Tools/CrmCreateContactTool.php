<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\ContactChannelType;
use App\Enums\ContactSource;
use App\Enums\LogChannel;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use Illuminate\Support\Facades\DB;

final class CrmCreateContactTool implements AgentTool
{
    public function key(): string
    {
        return 'crm.create_contact';
    }

    public function description(): string
    {
        return 'Create a lead contact, or return an existing match on email/phone. Sets source to ai_agent.';
    }

    public function schema(): array
    {
        return [
            'first_name' => [
                'type' => 'string',
                'required' => true,
                'description' => 'First name',
            ],
            'last_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Last name',
            ],
            'email' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Email address',
            ],
            'phone' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Phone number',
            ],
            'notes' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional note to attach',
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
        $email = isset($arguments['email']) ? trim((string) $arguments['email']) : '';
        $phone = isset($arguments['phone']) ? trim((string) $arguments['phone']) : '';

        $matched = $this->matchExisting($email, $phone);
        if ($matched !== null) {
            return ToolResult::ok(
                [
                    'contact_id' => $matched->id,
                    'matched' => true,
                    'first_name' => $matched->first_name,
                    'last_name' => $matched->last_name,
                ],
                "An existing contact matched ({$matched->first_name} {$matched->last_name}). No new contact was created.",
                (new FactBag)->identifier((string) $matched->id)->number($matched->id),
                resultType: 'contact',
                resultId: $matched->id,
                entities: [EntityRef::contact($matched)],
            );
        }

        $contact = DB::transaction(function () use ($arguments, $email, $phone, $principal, $ctx): Contact {
            $contact = Contact::query()->create([
                'first_name' => (string) $arguments['first_name'],
                'last_name' => isset($arguments['last_name']) ? (string) $arguments['last_name'] : '',
                'email' => $email !== '' ? $email : null,
                'source' => ContactSource::AiAgent,
                'created_by' => AgentWriteAttribution::employeeId($ctx),
            ]);

            if ($principal->siteId !== null) {
                $contact->sites()->syncWithoutDetaching([$principal->siteId]);
            }

            if ($email !== '') {
                $contact->channels()->create([
                    'type' => ContactChannelType::Email,
                    'value' => $email,
                    'is_primary' => true,
                ]);
            }

            if ($phone !== '') {
                $contact->channels()->create([
                    'type' => ContactChannelType::Phone,
                    'value' => $phone,
                    'is_primary' => true,
                ]);
            }

            $notes = isset($arguments['notes']) ? trim((string) $arguments['notes']) : '';
            $employeeId = AgentWriteAttribution::employeeId($ctx);
            if ($notes !== '' && $employeeId !== null) {
                $contact->notes()->create([
                    'employee_id' => $employeeId,
                    'content' => $notes,
                ]);
            }

            AgentWriteAttribution::log(LogChannel::Crm, 'contact.created', $contact, $ctx, [
                'source' => ContactSource::AiAgent->value,
            ]);

            return $contact;
        });

        return ToolResult::ok(
            [
                'contact_id' => $contact->id,
                'matched' => false,
                'source' => ContactSource::AiAgent->value,
            ],
            "Created contact {$contact->first_name} {$contact->last_name} (id {$contact->id}).",
            (new FactBag)->identifier((string) $contact->id)->number($contact->id),
            resultType: 'contact',
            resultId: $contact->id,
            entities: [EntityRef::contact($contact)],
        );
    }

    private function matchExisting(string $email, string $phone): ?Contact
    {
        if ($email !== '') {
            $byEmail = Contact::query()
                ->where('email', ContactChannelMatcher::normalizeEmail($email))
                ->first();
            if ($byEmail !== null) {
                return $byEmail;
            }

            $match = ContactChannelMatcher::match(Channel::Email, $email);
            if ($match['contact'] instanceof Contact) {
                return $match['contact'];
            }
        }

        if ($phone !== '') {
            $normalized = ContactChannelMatcher::normalizePhone($phone);
            if ($normalized !== '') {
                $byPhone = ContactChannel::query()
                    ->where('type', ContactChannelType::Phone)
                    ->get()
                    ->first(function (ContactChannel $row) use ($normalized): bool {
                        return ContactChannelMatcher::normalizePhone((string) $row->value) === $normalized;
                    });
                if ($byPhone?->contact instanceof Contact) {
                    return $byPhone->contact;
                }
            }

            $match = ContactChannelMatcher::match(Channel::Sms, $phone);
            if ($match['contact'] instanceof Contact) {
                return $match['contact'];
            }
        }

        return null;
    }
}
