<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Middleware\MetersUsage;
use App\Ai\Tools\CreateContact;
use App\Ai\Tools\CreateContactAddress;
use App\Ai\Tools\CreateContactChannel;
use App\Ai\Tools\CreateDeal;
use App\Ai\Tools\CreateNote;
use App\Ai\Tools\CreateOffer;
use App\Ai\Tools\CreateReservation;
use App\Ai\Tools\CreateTask;
use App\Ai\Tools\FetchObjects;
use App\Ai\Tools\SetCustomProperty;
use App\Models\Employee;
use App\Support\Ai\AiProviderRegistry;
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

    public function __construct(
        public Employee $employee,
        public bool $voice = false,
    ) {}

    public function middleware(): array
    {
        return [
            MetersUsage::class,
        ];
    }

    /**
     * Resolves to the company's configured default AiProviderAccount, or
     * null (today's unconfigured behavior — falls back to config('ai.default'))
     * when none is set up yet. See app/Support/Ai/AiProviderRegistry.php.
     */
    public function provider(): ?string
    {
        return app(AiProviderRegistry::class)->applyActiveCredentials();
    }

    public function model(): ?string
    {
        return app(AiProviderRegistry::class)->activeModel();
    }

    public function instructions(): Stringable|string
    {
        $prompt = <<<'PROMPT'
You are a helpful CRM copilot assistant for a storage rental platform. Your role is to help users manage their contacts and deals efficiently.

You have one read tool, FetchObjects, that can retrieve: contacts, deals, offers, reservations, contracts,
payments, delinquency/overdue cases, message threads, and an entity's custom property values (object_type:
"custom_property", with entity_type + entity_id). Use its filters (search, contact_id, deal_id, contract_id,
status) to scope results instead of fetching everything and filtering yourself.

You can create exactly these things, and nothing else: a contact, a deal, an offer, a reservation, a note
(on a contact, deal, offer, reservation, or contract), a task (on a contact or deal only), an address or
communication channel for a contact, and you can set a custom property value on any entity that supports one.
If asked to create or modify anything outside this list, say so rather than attempting it.

Every write tool requires both operator approval and the operator's own permission for that action — a run can
pause for approval and still be denied afterwards if the operator lacks the underlying permission. When you
intend to create, add, or set something, call the tool directly — do not ask a yes/no question in chat first
and wait for a typed reply. The run pauses automatically and the approval UI handles confirmation; asking in
chat first only adds a redundant, confusing extra step. If a tool result reports a permission error, tell the
user plainly rather than retrying.

When helping users:
1. Search for existing records with FetchObjects before suggesting to create new ones
2. Provide clear summaries of actions taken
3. Ask clarifying questions when needed
4. Help users track their sales pipeline and contact relationships

Be conversational, helpful, and efficient in your responses.
PROMPT;

        if (! $this->voice) {
            return $prompt;
        }

        return $prompt."\n\n".<<<'VOICE'
This turn is spoken aloud. Answer in at most two short spoken sentences.
No markdown, no bullet lists, no tables, no headings, no emoji.
Never speak ids, uuids or URLs. Refer to records by name and unit number.
Currency as words with the figure intact ("one hundred twenty euros"), never €120.00.
If the answer is a list of more than three records, say how many there are and that the detail is on screen. Do not enumerate.
If a tool needs approval, say what you are about to do in one sentence and stop.
VOICE;
    }

    public function tools(): iterable
    {
        return [
            (new CreateContact($this->employee))->requireApproval('Create a contact'),
            (new CreateDeal($this->employee))->requireApproval('Create a deal'),
            (new CreateOffer($this->employee))->requireApproval('Create an offer'),
            (new CreateReservation($this->employee))->requireApproval('Create a reservation'),
            (new CreateNote($this->employee))->requireApproval('Add a note'),
            (new CreateTask($this->employee))->requireApproval('Add a task'),
            (new CreateContactAddress($this->employee))->requireApproval('Add a contact address'),
            (new CreateContactChannel($this->employee))->requireApproval('Add a contact channel'),
            (new SetCustomProperty($this->employee))->requireApproval('Set a custom property'),
            new FetchObjects($this->employee),
        ];
    }
}
