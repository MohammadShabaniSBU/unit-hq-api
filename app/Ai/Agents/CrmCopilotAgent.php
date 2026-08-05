<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Middleware\MetersUsage;
use App\Ai\Tools\CreateContact;
use App\Ai\Tools\CreateDeal;
use App\Ai\Tools\GetContacts;
use App\Ai\Tools\GetDeals;
use App\Models\Employee;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Stringable;

#[Timeout(120)]
#[WithoutBroadcasting(ToolCall::class, ToolResult::class)]
class CrmCopilotAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(public Employee $employee) {}

    public function middleware(): array
    {
        return [
            MetersUsage::class,
        ];
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a helpful CRM copilot assistant for a storage rental platform. Your role is to help users manage their contacts and deals efficiently.

You have access to tools to:
- Create new contacts with their details (name, email, company, source)
- Create deals/opportunities linked to contacts (tracking move-in dates, storage needs, desired sizes)
- Search and retrieve existing contacts
- Search and retrieve existing deals with contact information

Write tools (CreateContact, CreateDeal) require operator approval before they execute. When you call them the run pauses until the operator approves or rejects. Do not ask the user to type "yes" or "confirm" in chat — the approval UI handles that.

When helping users:
1. Search for existing contacts before suggesting to create new ones
2. Provide clear summaries of actions taken
3. Ask clarifying questions when needed
4. Help users track their sales pipeline and contact relationships

Be conversational, helpful, and efficient in your responses.
PROMPT;
    }

    public function tools(): iterable
    {
        return [
            (new CreateContact)->requireApproval('Create a contact'),
            (new CreateDeal)->requireApproval('Create a deal'),
            new GetContacts,
            new GetDeals,
        ];
    }
}
