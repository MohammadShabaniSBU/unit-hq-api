<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Middleware\MetersUsage;
use App\Ai\Tools\TemplateAssist\AddSection;
use App\Ai\Tools\TemplateAssist\GetDocument;
use App\Ai\Tools\TemplateAssist\InsertBlocks;
use App\Ai\Tools\TemplateAssist\ListTokens;
use App\Ai\Tools\TemplateAssist\MoveBlock;
use App\Ai\Tools\TemplateAssist\MoveSection;
use App\Ai\Tools\TemplateAssist\RemoveNode;
use App\Ai\Tools\TemplateAssist\SetSectionLayout;
use App\Ai\Tools\TemplateAssist\SetSubject;
use App\Ai\Tools\TemplateAssist\UpdateBlock;
use App\Ai\Tools\TemplateAssist\UpdateSection;
use App\Models\Employee;
use App\Models\TemplateAssistTurn;
use App\Support\Ai\AiProviderRegistry;
use App\Support\Templates\Assist\AssistContext;
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
use RuntimeException;

#[Timeout(120)]
#[WithoutBroadcasting(ToolCall::class, ToolResult::class)]
final class TemplateAssistantAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(
        public Employee $employee,
        public int $turnId,
    ) {}

    public function middleware(): array
    {
        return [
            MetersUsage::class,
        ];
    }

    public function provider(): ?string
    {
        return app(AiProviderRegistry::class)->applyActiveCredentials();
    }

    public function model(): ?string
    {
        return app(AiProviderRegistry::class)->activeModel();
    }

    public function instructions(): string
    {
        $path = resource_path('prompts/template-assistant.md');
        $prompt = file_get_contents($path);
        if ($prompt === false) {
            throw new RuntimeException('Template assistant prompt is missing.');
        }

        $turn = TemplateAssistTurn::query()->with('session.family')->findOrFail($this->turnId);
        $session = $turn->session;
        if ($session === null || $session->family === null) {
            throw new RuntimeException('Template assist turn has no session.');
        }

        $context = AssistContext::for(
            $session->family,
            $session->locale,
            is_array($turn->selection) ? $turn->selection : null,
            is_array($turn->base_document) ? $turn->base_document : null,
        );

        return rtrim($prompt)."\n\nTemplate context:\n".$context->toPromptBlock();
    }

    public function tools(): iterable
    {
        return [
            new GetDocument($this->turnId),
            new ListTokens($this->turnId),
            new AddSection($this->turnId),
            new SetSectionLayout($this->turnId),
            new UpdateSection($this->turnId),
            new InsertBlocks($this->turnId),
            new UpdateBlock($this->turnId),
            new MoveBlock($this->turnId),
            new MoveSection($this->turnId),
            new RemoveNode($this->turnId),
            new SetSubject($this->turnId),
        ];
    }
}
