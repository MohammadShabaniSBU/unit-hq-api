<?php

namespace App\Ai\Tools;

use App\Enums\DealStatus;
use App\Enums\StayPeriod;
use App\Enums\StorageReason;
use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateDeal implements Tool
{
    public function description(): Stringable|string
    {
        return 'Create a new deal/opportunity for an existing contact, tracking their expected move-in date, stay period, and storage needs.';
    }

    public function handle(Request $request): Stringable|string
    {
        $contact = Contact::query()->findOrFail($request['contact_id']);

        $deal = Deal::query()->create([
            'contact_id' => $contact->id,
            'status' => $request['status'] ?? DealStatus::New->value,
            'expected_move_in' => $request['expected_move_in'] ?? null,
            'expected_stay_length' => $request['expected_stay_length'] ?? null,
            'expected_stay_period' => $request['expected_stay_period'] ?? null,
            'storage_reason' => $request['storage_reason'] ?? null,
            'desired_size' => $request['desired_size'] ?? null,
            'desired_unit_class_id' => $request['desired_unit_class_id'] ?? null,
        ]);

        return json_encode([
            'success' => true,
            'message' => "Deal created for contact '{$contact->first_name} {$contact->last_name}'.",
            'deal_id' => $deal->id,
            'contact_name' => "{$contact->first_name} {$contact->last_name}",
            'status' => $deal->status->value,
            'expected_move_in' => $deal->expected_move_in?->format('Y-m-d'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->integer()
                ->description('ID of the contact for this deal')
                ->required(),
            'status' => $schema->string()
                ->description('Deal status in the pipeline')
                ->enum(['new', 'contacted', 'qualified', 'offer_sent', 'offer_viewed', 'negotiating', 'closed_won', 'closed_lost', 'unresponsive'])
                ->nullable(),
            'expected_move_in' => $schema->string()
                ->description('Expected move-in date (YYYY-MM-DD format)')
                ->nullable(),
            'expected_stay_length' => $schema->integer()
                ->description('Expected stay length as a number')
                ->nullable(),
            'expected_stay_period' => $schema->string()
                ->description('Unit of stay period (day, week, or month)')
                ->enum(['day', 'week', 'month'])
                ->nullable(),
            'storage_reason' => $schema->string()
                ->description('Reason for storage need')
                ->enum(['freelancer', 'business_extra_space', 'startup', 'other_business_need', 'other_personal_use', 'new_home', 'house_renovations', 'travelling', 'decluttering', 'charity_non_profit', 'other', 'personal', 'business', 'student'])
                ->nullable(),
            'desired_size' => $schema->string()
                ->description('Desired unit size (numeric value)')
                ->nullable(),
            'desired_unit_class_id' => $schema->integer()
                ->description('ID of the desired unit class')
                ->nullable(),
        ];
    }
}
