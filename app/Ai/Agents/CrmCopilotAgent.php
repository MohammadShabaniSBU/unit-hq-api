<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CreateContact;
use App\Ai\Tools\CreateDeal;
use App\Ai\Tools\GetContacts;
use App\Ai\Tools\GetDeals;
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

When helping users:
1. Always confirm the information before creating new records
2. Search for existing contacts before suggesting to create new ones
3. Provide clear summaries of actions taken
4. Ask clarifying questions when needed
5. Help users track their sales pipeline and contact relationships

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
            new CreateContact,
            new CreateDeal,
            new GetContacts,
            new GetDeals,
        ];
    }
}
