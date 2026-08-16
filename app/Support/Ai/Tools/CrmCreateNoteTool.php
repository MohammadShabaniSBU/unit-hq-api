<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\LogChannel;
use App\Models\Note;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;

final class CrmCreateNoteTool implements AgentTool
{
    public function key(): string
    {
        return 'crm.create_note';
    }

    public function description(): string
    {
        return 'Append a note on a contact or deal. Notes are never edited.';
    }

    public function schema(): array
    {
        return [
            'content' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Note body',
            ],
            'related_to_type' => [
                'type' => 'string',
                'required' => true,
                'enum' => AllowlistedParent::TYPES,
                'description' => 'Parent morph alias: contact or deal',
            ],
            'related_to_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Parent id',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::ChannelAsserted;
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
        $employeeId = AgentWriteAttribution::requireEmployeeId($ctx);
        if ($employeeId instanceof ToolResult) {
            return $employeeId;
        }

        $parent = AllowlistedParent::resolve(
            (string) $arguments['related_to_type'],
            (int) $arguments['related_to_id'],
            $principal,
        );
        if ($parent instanceof ToolResult) {
            return $parent;
        }

        $note = Note::query()->create([
            'notable_type' => $parent->getMorphClass(),
            'notable_id' => $parent->getKey(),
            'employee_id' => $employeeId,
            'content' => (string) $arguments['content'],
        ]);

        AgentWriteAttribution::log(LogChannel::Crm, 'note.created', $parent, $ctx, [
            'note_id' => $note->id,
        ]);

        return ToolResult::ok(
            [
                'note_id' => $note->id,
                'related_to_type' => $parent->getMorphClass(),
                'related_to_id' => $parent->getKey(),
            ],
            "Logged note {$note->id}.",
            (new FactBag)->identifier((string) $note->id)->number($note->id),
        );
    }
}
