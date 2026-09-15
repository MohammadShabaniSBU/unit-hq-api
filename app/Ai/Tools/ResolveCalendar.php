<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Employee;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Tools\CalendarResolveTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Operator-facing adapter around CalendarResolveTool. Copilot stays on the
 * Laravel AI SDK path, so it cannot take the customer-agent AgentTool as-is.
 */
class ResolveCalendar implements Tool
{
    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Resolve a relative-date phrase (next Monday, in 2 weeks, 15 January) to an ISO civil date. '
            .'Pass the operator\'s exact words. Never compute a date yourself.';
    }

    public function handle(Request $request): Stringable|string
    {
        $phrase = (string) ($request['phrase'] ?? '');
        $rawSiteId = $request['site_id'] ?? null;
        $siteId = $rawSiteId !== null && $rawSiteId !== '' ? (int) $rawSiteId : null;
        if ($siteId !== null && $siteId <= 0) {
            $siteId = null;
        }

        $arguments = ['phrase' => $phrase];
        if ($siteId !== null) {
            $arguments['site_id'] = $siteId;
        }

        $result = (new CalendarResolveTool)->handle(
            AgentPrincipal::employee($this->employee->id, $siteId, (string) app()->getLocale()),
            $arguments,
        );

        if ($result->status !== ToolInvocationStatus::Ok) {
            return json_encode([
                'success' => false,
                'error' => $result->display,
            ]);
        }

        return json_encode([
            'success' => true,
            'iso' => $result->data['iso'],
            'weekday' => $result->data['weekday'],
            'phrase' => $result->data['phrase'],
            'timezone' => $result->data['timezone'],
            'display' => $result->display,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'phrase' => $schema->string()
                ->description("The operator's words, verbatim, at most 60 characters")
                ->required(),
            'site_id' => $schema->integer()
                ->description('Site whose timezone defines today. Omit to use the app timezone.')
                ->nullable(),
        ];
    }
}
