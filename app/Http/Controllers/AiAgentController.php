<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AiAgentResource;
use App\Models\AiAgent;
use App\Models\Employee;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiAgentController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);

        $agents = AiAgent::query()->active()->with('writePolicies')->orderBy('key')->get();

        return response()->json([
            'message' => 'Agents retrieved successfully.',
            'data' => AiAgentResource::collection($agents)->resolve(),
            'meta' => [
                'demo_enabled' => filter_var(config('agents.demo_enabled'), FILTER_VALIDATE_BOOLEAN),
            ],
        ]);
    }

    public function updateWritePolicy(Request $request, AiAgent $aiAgent): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'tool_key' => ['required', 'string', 'max:100'],
            'mode' => ['required', Rule::enum(WritePolicyMode::class)],
            'max_per_conversation' => ['nullable', 'integer', 'min:1'],
            'max_per_day' => ['nullable', 'integer', 'min:1'],
            'min_verification' => ['nullable', Rule::enum(VerificationLevel::class)],
        ]);

        $toolKey = $validated['tool_key'];
        $definitions = app(AgentRegistry::class);
        $tools = app(ToolRegistry::class);

        if (
            ! $definitions->has($aiAgent->key)
            || ! in_array($toolKey, $definitions->get($aiAgent->key)->toolKeys(), true)
            || ! $tools->has($toolKey)
        ) {
            throw ValidationException::withMessages([
                'tool_key' => ['This tool is not on this agent.'],
            ]);
        }

        $tool = $tools->get($toolKey);
        if (! $tool->isWrite()) {
            throw ValidationException::withMessages([
                'tool_key' => ['Only write tools have a write policy.'],
            ]);
        }

        $mode = WritePolicyMode::from($validated['mode']);
        if ($mode === WritePolicyMode::Propose && ! $tool instanceof ProposableTool) {
            throw ValidationException::withMessages([
                'mode' => ['This tool cannot run in needs-approval mode.'],
            ]);
        }

        $min = isset($validated['min_verification'])
            ? VerificationLevel::from($validated['min_verification'])
            : null;

        if ($min !== null && $min->rank() < $tool->requiredVerification()->rank()) {
            throw ValidationException::withMessages([
                'min_verification' => ['Minimum verification can raise the tool floor, never lower it.'],
            ]);
        }

        $aiAgent->writePolicies()->updateOrCreate(
            ['tool_key' => $toolKey],
            [
                'mode' => $mode,
                'max_per_conversation' => $validated['max_per_conversation'] ?? null,
                'max_per_day' => $validated['max_per_day'] ?? null,
                'min_verification' => $min,
                'updated_by_employee_id' => $employee->id,
            ],
        );

        $aiAgent->load('writePolicies');

        return $this->success(
            AiAgentResource::make($aiAgent),
            'Write policy updated.',
        );
    }
}
