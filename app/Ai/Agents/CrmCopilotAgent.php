<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CreateContact;
use App\Ai\Tools\CreateDeal;
use App\Ai\Tools\GetContacts;
use App\Ai\Tools\GetDeals;
use App\Ai\Tools\RequestConfirmation;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class CrmCopilotAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        private array $messageHistory = []
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a helpful CRM copilot assistant for a storage rental platform. Your role is to help users manage their contacts and deals efficiently.

You have access to tools to:
- Create new contacts with their details (name, email, company, source)
- Create deals/opportunities linked to contacts (tracking move-in dates, storage needs, desired sizes)
- Search and retrieve existing contacts
- Search and retrieve existing deals with contact information
- Request user confirmation before performing write actions

Write tools that require confirmation: CreateContact, CreateDeal, and any future create/update/delete tools.

When performing a write action:
1. Before calling ANY write tool, call RequestConfirmation first with:
   - action_type: the action identifier (e.g. create_contact, create_deal)
   - summary: a one-line description of what will happen
   - fields: key-value pairs of the exact data you plan to submit
2. After calling RequestConfirmation, stop and wait for the user to explicitly confirm
3. Only call the write tool after the user confirms (e.g. "yes", "proceed", "confirm")
4. If the user declines, do not perform the write action
5. Call RequestConfirmation only once per write action. If the user's latest message confirms a pending action (e.g. "yes", "proceed", "confirm", "go ahead"), do NOT call RequestConfirmation again — call the write tool directly with the previously confirmed data
6. Never call RequestConfirmation and a write tool in the same turn after the user has already confirmed

When helping users:
1. Search for existing contacts before suggesting to create new ones
2. Provide clear summaries of actions taken
3. Ask clarifying questions when needed
4. Help users track their sales pipeline and contact relationships

Be conversational, helpful, and efficient in your responses.
PROMPT;
    }

    public function messages(): iterable
    {
        return collect($this->messageHistory)
            ->map(fn (array $m) => new Message($m['role'], $m['content']))
            ->all();
    }

    public function tools(): iterable
    {
        return [
            new RequestConfirmation,
            new CreateContact,
            new CreateDeal,
            new GetContacts,
            new GetDeals,
        ];
    }
}
