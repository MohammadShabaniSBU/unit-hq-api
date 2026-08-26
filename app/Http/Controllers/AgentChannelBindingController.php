<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AgentChannelBindingResource;
use App\Models\AgentChannelBinding;
use App\Models\Employee;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentChannelBindingController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::AiAgentBindingManage->value);

        $bindings = AgentChannelBinding::query()
            ->live()
            ->with(['agent', 'site', 'updatedBy'])
            ->orderBy('channel')
            ->orderBy('site_id')
            ->get();

        return $this->success(
            AgentChannelBindingResource::collection($bindings)->resolve(),
            'Bindings retrieved successfully.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AiAgentBindingManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $this->validated($request);
        $this->assertBindableChannel($validated['channel']);
        $this->assertUniqueChannelSite($validated['channel'], $validated['site_id'] ?? null);

        try {
            $binding = DB::transaction(function () use ($validated, $employee): AgentChannelBinding {
                $binding = AgentChannelBinding::query()->create([
                    'ai_agent_id' => $validated['ai_agent_id'],
                    'channel' => $validated['channel'],
                    'site_id' => $validated['site_id'] ?? null,
                    'mode' => $validated['mode'],
                    'audience' => $validated['audience'],
                    'outside_hours' => $validated['outside_hours'],
                    'updated_by_employee_id' => $employee->id,
                ]);

                $this->recordActivity('ai.binding.created', $binding, $employee);

                return $binding;
            });
        } catch (UniqueConstraintViolationException) {
            $this->throwDuplicate();
        }

        $binding->load(['agent', 'site', 'updatedBy']);

        return $this->created(
            AgentChannelBindingResource::make($binding),
            'Binding created.',
        );
    }

    public function update(Request $request, AgentChannelBinding $binding): JsonResponse
    {
        Gate::authorize(Permission::AiAgentBindingManage->value);

        if ($binding->isArchived()) {
            throw ValidationException::withMessages([
                'binding' => ['This binding is archived.'],
            ]);
        }

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $this->validated($request);
        $this->assertBindableChannel($validated['channel']);
        $this->assertUniqueChannelSite(
            $validated['channel'],
            $validated['site_id'] ?? null,
            $binding->id,
        );

        try {
            DB::transaction(function () use ($binding, $validated, $employee): void {
                $binding->update([
                    'ai_agent_id' => $validated['ai_agent_id'],
                    'channel' => $validated['channel'],
                    'site_id' => $validated['site_id'] ?? null,
                    'mode' => $validated['mode'],
                    'audience' => $validated['audience'],
                    'outside_hours' => $validated['outside_hours'],
                    'updated_by_employee_id' => $employee->id,
                ]);

                $this->recordActivity('ai.binding.updated', $binding, $employee);
            });
        } catch (UniqueConstraintViolationException) {
            $this->throwDuplicate();
        }

        $binding->load(['agent', 'site', 'updatedBy']);

        return $this->success(
            AgentChannelBindingResource::make($binding),
            'Binding updated.',
        );
    }

    public function destroy(Request $request, AgentChannelBinding $binding): JsonResponse
    {
        Gate::authorize(Permission::AiAgentBindingManage->value);

        if ($binding->isArchived()) {
            throw ValidationException::withMessages([
                'binding' => ['This binding is archived.'],
            ]);
        }

        /** @var Employee $employee */
        $employee = $request->user();

        DB::transaction(function () use ($binding, $employee): void {
            $binding->archived_at = now();
            $binding->updated_by_employee_id = $employee->id;
            $binding->save();

            $this->recordActivity('ai.binding.archived', $binding, $employee);
        });

        $binding->load(['agent', 'site', 'updatedBy']);

        return $this->success(
            AgentChannelBindingResource::make($binding),
            'Binding archived.',
        );
    }

    /**
     * @return array{
     *     ai_agent_id: int,
     *     channel: string,
     *     site_id?: int|null,
     *     mode: string,
     *     audience: string,
     *     outside_hours: string
     * }
     */
    private function validated(Request $request): array
    {
        /** @var array{
         *     ai_agent_id: int,
         *     channel: string,
         *     site_id?: int|null,
         *     mode: string,
         *     audience: string,
         *     outside_hours: string
         * } $validated
         */
        $validated = $request->validate([
            'ai_agent_id' => [
                'required',
                'integer',
                Rule::exists('ai_agents', 'id')->where('is_active', true)->whereNull('archived_at'),
            ],
            'channel' => ['required', Rule::enum(AgentChannel::class)],
            'site_id' => [
                'nullable',
                'integer',
                Rule::exists('sites', 'id')->whereNull('archived_at'),
            ],
            'mode' => ['required', Rule::enum(BindingMode::class)],
            'audience' => ['required', Rule::enum(BindingAudience::class)],
            'outside_hours' => ['required', Rule::enum(OutsideHoursPolicy::class)],
        ]);

        return $validated;
    }

    private function assertBindableChannel(string $channel): void
    {
        if (! AgentChannel::from($channel)->isBindable()) {
            throw ValidationException::withMessages([
                'channel' => ['This channel cannot be bound to an agent.'],
            ]);
        }
    }

    private function assertUniqueChannelSite(string $channel, ?int $siteId, ?int $exceptId = null): void
    {
        $query = AgentChannelBinding::query()
            ->live()
            ->where('channel', $channel);

        if ($siteId === null) {
            $query->whereNull('site_id');
        } else {
            $query->where('site_id', $siteId);
        }

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            $this->throwDuplicate();
        }
    }

    private function throwDuplicate(): never
    {
        throw ValidationException::withMessages([
            'channel' => ['A live binding already exists for this channel and site.'],
        ]);
    }

    private function recordActivity(string $event, AgentChannelBinding $binding, Employee $employee): void
    {
        RecordsActivity::core($event, $binding, [
            'channel' => $binding->channel->value,
            'site_id' => $binding->site_id,
            'mode' => $binding->mode->value,
            'audience' => $binding->audience->value,
        ], $employee);
    }
}
