<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\LogChannel;
use App\Support\Ai\AgentContext;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;

final class AgentWriteAttribution
{
    public const NOTES_NOT_WRITTEN = 'Note was not written: this conversation has no operator attribution.';

    public static function employeeId(?AgentContext $ctx): ?int
    {
        $id = $ctx?->conversation->created_by_employee_id;

        return $id !== null ? (int) $id : null;
    }

    public static function requireEmployeeId(?AgentContext $ctx): int|ToolResult
    {
        $id = self::employeeId($ctx);
        if ($id === null) {
            return ToolResult::error('Cannot write without an operator attribution.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        LogChannel $channel,
        string $event,
        Model $subject,
        ?AgentContext $ctx,
        array $properties = [],
    ): void {
        RecordsActivity::log($channel, $event, $subject, $properties, $ctx?->agent);
    }
}
