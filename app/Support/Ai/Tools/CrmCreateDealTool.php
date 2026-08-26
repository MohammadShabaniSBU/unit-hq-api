<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Enums\LogChannel;
use App\Enums\StayPeriod;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
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
            'expected_move_in' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Expected move-in date YYYY-MM-DD. Convert relative dates using the date in the prompt first.',
            ],
            'expected_stay_length' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Expected stay length as a positive integer. Must be paired with expected_stay_period.',
            ],
            'expected_stay_period' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['week', 'month'],
                'description' => 'Stay period unit (week or month). Must be paired with expected_stay_length.',
            ],
            'desired_size_m2' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Desired unit size in square metres (e.g. 12 or 12.5).',
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
        return [
            'contact_id' => EntityType::Contact,
            'site_id' => EntityType::Site,
            'unit_class_id' => EntityType::UnitClass,
        ];
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

        $needs = $this->validatedNeeds($arguments);
        if ($needs instanceof ToolResult) {
            return $needs;
        }

        $deal = Deal::query()->create([
            'contact_id' => $contact->id,
            'site_id' => isset($arguments['site_id']) ? (int) $arguments['site_id'] : $principal->siteId,
            'desired_unit_class_id' => isset($arguments['unit_class_id']) ? (int) $arguments['unit_class_id'] : null,
            'expected_move_in' => $needs['expected_move_in'],
            'expected_stay_length' => $needs['expected_stay_length'],
            'expected_stay_period' => $needs['expected_stay_period'],
            'desired_size' => $needs['desired_size'],
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

        $bits = ["Opened deal {$deal->id} for contact {$contact->id}."];
        $facts = (new FactBag)->identifier((string) $deal->id)->number($deal->id)->number($contact->id);
        if ($deal->expected_move_in !== null) {
            $moveIn = $deal->expected_move_in->toDateString();
            $bits[] = "Expected move-in {$moveIn}.";
            $facts->date($moveIn);
        }
        if ($deal->expected_stay_length !== null && $deal->expected_stay_period instanceof StayPeriod) {
            $bits[] = "Expected stay {$deal->expected_stay_length} {$deal->expected_stay_period->value}.";
        }
        if ($deal->desired_size !== null) {
            $bits[] = "Desired size {$deal->desired_size} m².";
        }
        if ($notes !== '' && $employeeId === null) {
            $bits[] = AgentWriteAttribution::NOTES_NOT_WRITTEN;
        }

        $display = implode(' ', $bits);
        $facts->absorb($display);

        return ToolResult::ok(
            [
                'deal_id' => $deal->id,
                'contact_id' => $contact->id,
                'status' => DealStatus::New->value,
                'expected_move_in' => $deal->expected_move_in?->toDateString(),
                'expected_stay_length' => $deal->expected_stay_length,
                'expected_stay_period' => $deal->expected_stay_period?->value,
                'desired_size_m2' => $deal->desired_size,
            ],
            $display,
            $facts,
            resultType: 'deal',
            resultId: $deal->id,
            entities: [
                EntityRef::deal($deal),
                EntityRef::contact($contact),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{expected_move_in: string|null, expected_stay_length: int|null, expected_stay_period: string|null, desired_size: string|null}|ToolResult
     */
    private function validatedNeeds(array $arguments): array|ToolResult
    {
        $moveIn = isset($arguments['expected_move_in']) ? trim((string) $arguments['expected_move_in']) : '';
        if ($moveIn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $moveIn) !== 1) {
            return ToolResult::fail(ToolError::invalidArguments(
                'expected_move_in must be an ISO date (YYYY-MM-DD).',
                ['hint' => 'convert relative dates using the date in the prompt before calling'],
            ));
        }

        $hasLength = array_key_exists('expected_stay_length', $arguments)
            && $arguments['expected_stay_length'] !== null
            && $arguments['expected_stay_length'] !== '';
        $hasPeriod = array_key_exists('expected_stay_period', $arguments)
            && $arguments['expected_stay_period'] !== null
            && $arguments['expected_stay_period'] !== '';
        if ($hasLength !== $hasPeriod) {
            return ToolResult::fail(ToolError::invalidArguments(
                'expected_stay_length and expected_stay_period must be supplied together.',
                ['hint' => 'pass both expected_stay_length and expected_stay_period, or neither'],
            ));
        }

        $length = null;
        $period = null;
        if ($hasLength) {
            $length = (int) $arguments['expected_stay_length'];
            if ($length < 1) {
                return ToolResult::fail(ToolError::invalidArguments(
                    'expected_stay_length must be at least 1.',
                    ['hint' => 'pass a positive stay length'],
                ));
            }
            $period = (string) $arguments['expected_stay_period'];
        }

        $size = isset($arguments['desired_size_m2']) ? trim((string) $arguments['desired_size_m2']) : '';
        if ($size !== '' && preg_match('/^\d{1,4}(\.\d{1,2})?$/', $size) !== 1) {
            return ToolResult::fail(ToolError::invalidArguments(
                'desired_size_m2 must be a decimal square-metre value.',
                ['hint' => 'pass a size like 12 or 12.5'],
            ));
        }

        return [
            'expected_move_in' => $moveIn !== '' ? $moveIn : null,
            'expected_stay_length' => $length,
            'expected_stay_period' => $period,
            'desired_size' => $size !== '' ? $size : null,
        ];
    }
}
