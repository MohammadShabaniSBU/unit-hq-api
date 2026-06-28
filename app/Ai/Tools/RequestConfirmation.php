<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RequestConfirmation implements Tool
{
    public function description(): Stringable|string
    {
        return 'Request user confirmation before performing a write action. Call this before any create/update/delete tool with the exact data you plan to submit.';
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode([
            'needs_confirmation' => true,
            'action_type' => $request['action_type'],
            'summary' => $request['summary'],
            'fields' => $request['fields'] ?? [],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action_type' => $schema->string()
                ->description('The type of action to confirm (e.g. create_contact, create_deal, create_offer, create_unit, create_task)')
                ->required(),
            'summary' => $schema->string()
                ->description('One-line human-readable description of the action (e.g. "Create contact John Doe")')
                ->required(),
            'fields' => $schema->object()
                ->description('Key-value pairs of the data that will be created or modified')
                ->required(),
        ];
    }
}
