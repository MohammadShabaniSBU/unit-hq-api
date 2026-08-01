<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Http\Resources\PlaybookEnrolmentResource;
use App\Http\Resources\PlaybookResource;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Support\Automation\RunLifecycle;
use App\Support\Playbooks\DebtPlaybookOverlap;
use App\Support\Playbooks\PlaybookCompiler;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlaybookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(PlaybookKind::class)],
            'search' => ['nullable', 'string'],
        ]);

        $query = Playbook::query()
            ->with('steps')
            ->active()
            ->latest();

        if (! empty($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%'.trim($validated['search']).'%');
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (Playbook $playbook) => PlaybookResource::make($playbook),
            ),
            'Playbooks retrieved successfully.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request, creating: true);

        $playbook = DB::transaction(function () use ($validated): Playbook {
            $playbook = Playbook::query()->create([
                'kind' => $validated['kind'],
                'name' => $validated['name'],
                'is_active' => false,
                'enrolment_filters' => $validated['enrolment_filters'] ?? [],
            ]);

            $this->syncSteps($playbook, $validated['steps'] ?? []);
            PlaybookCompiler::compile($playbook->fresh(['steps']) ?? $playbook);

            return $playbook->fresh(['steps']) ?? $playbook;
        });

        return $this->created(
            PlaybookResource::make($playbook),
            'Playbook created successfully.',
        );
    }

    public function show(Playbook $playbook): JsonResponse
    {
        $activeCount = AutomationRun::query()
            ->whereIn(
                'automation_id',
                Automation::query()->where('playbook_id', $playbook->id)->select('id'),
            )
            ->whereIn('status', PlaybookEnrolmentSummary::activeStatuses())
            ->count();

        return $this->success(
            PlaybookResource::make($playbook->load('steps'))->additional([
                'active_enrolment_count' => $activeCount,
            ]),
            'Playbook retrieved successfully.',
        );
    }

    public function enrolments(Request $request, Playbook $playbook): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['active', 'exited'])],
        ]);

        $query = AutomationRun::query()
            ->whereHas('automation', fn ($q) => $q->where('playbook_id', $playbook->id))
            ->with(['automation.nodes', 'steps'])
            ->latest('id');

        if (($validated['status'] ?? null) === 'active') {
            $query->whereIn('status', PlaybookEnrolmentSummary::activeStatuses());
        } elseif (($validated['status'] ?? null) === 'exited') {
            $query->whereIn('status', PlaybookEnrolmentSummary::exitedStatuses());
        }

        $paginator = $query->paginate($this->perPage());
        $subjects = PlaybookEnrolmentSummary::loadSubjects($paginator->getCollection());

        return $this->paginated(
            $paginator->through(
                fn (AutomationRun $run) => PlaybookEnrolmentResource::make($run)->additional([
                    'enrolment_subjects' => $subjects,
                ]),
            ),
            'Playbook enrolments retrieved successfully.',
        );
    }

    public function update(Request $request, Playbook $playbook): JsonResponse
    {
        if ($playbook->isArchived()) {
            throw ValidationException::withMessages([
                'playbook' => 'Cannot update an archived playbook.',
            ]);
        }

        $validated = $this->validatedPayload($request, creating: false);

        DB::transaction(function () use ($playbook, $validated): void {
            $fields = [];
            if (array_key_exists('name', $validated)) {
                $fields['name'] = $validated['name'];
            }
            if (array_key_exists('enrolment_filters', $validated)) {
                $fields['enrolment_filters'] = $validated['enrolment_filters'] ?? [];
            }
            if ($fields !== []) {
                $playbook->update($fields);
            }

            if (array_key_exists('steps', $validated)) {
                $this->syncSteps($playbook, $validated['steps'] ?? []);
            }

            // Recompile whenever content that affects the graph changes.
            if (
                array_key_exists('name', $validated)
                || array_key_exists('enrolment_filters', $validated)
                || array_key_exists('steps', $validated)
            ) {
                PlaybookCompiler::compile($playbook->fresh(['steps']) ?? $playbook);
            }
        });

        return $this->success(
            PlaybookResource::make($playbook->fresh(['steps'])),
            'Playbook updated successfully.',
        );
    }

    public function destroy(Playbook $playbook): JsonResponse
    {
        if (! $playbook->isArchived()) {
            DB::transaction(function () use ($playbook): void {
                $playbook->update([
                    'archived_at' => now(),
                    'is_active' => false,
                ]);

                if ($playbook->automation_id !== null) {
                    Automation::query()
                        ->whereKey($playbook->automation_id)
                        ->update(['status' => AutomationStatus::Inactive->value]);
                }
            });
        }

        return $this->noContent('Playbook archived successfully.');
    }

    public function activate(Playbook $playbook): JsonResponse
    {
        if ($playbook->isArchived()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot activate an archived playbook.',
            ]);
        }

        DebtPlaybookOverlap::assertCanActivate($playbook);

        DB::transaction(function () use ($playbook): void {
            $playbook->update(['is_active' => true]);

            if ($playbook->automation_id === null) {
                PlaybookCompiler::compile($playbook->fresh(['steps']) ?? $playbook);
                $playbook->refresh();
            }

            Automation::query()
                ->whereKey($playbook->automation_id)
                ->update(['status' => AutomationStatus::Active->value]);
        });

        return $this->success(
            PlaybookResource::make($playbook->fresh(['steps'])),
            'Playbook activated successfully.',
        );
    }

    public function deactivate(Playbook $playbook): JsonResponse
    {
        DB::transaction(function () use ($playbook): void {
            $playbook->update(['is_active' => false]);

            if ($playbook->automation_id !== null) {
                Automation::query()
                    ->whereKey($playbook->automation_id)
                    ->update(['status' => AutomationStatus::Inactive->value]);
            }
        });

        return $this->success(
            PlaybookResource::make($playbook->fresh(['steps'])),
            'Playbook deactivated successfully.',
        );
    }

    public function exitEnrolments(Playbook $playbook): JsonResponse
    {
        $automationIds = Automation::query()
            ->where('playbook_id', $playbook->id)
            ->pluck('id')
            ->all();

        $cancelled = 0;

        if ($automationIds !== []) {
            $runs = AutomationRun::query()
                ->whereIn('automation_id', $automationIds)
                ->whereIn('status', [
                    AutomationRunStatus::Pending,
                    AutomationRunStatus::Running,
                    AutomationRunStatus::Waiting,
                ])
                ->get();

            foreach ($runs as $run) {
                if (RunLifecycle::cancel($run, AutomationCancelCause::Superseded)) {
                    $cancelled++;
                }
            }
        }

        return $this->success(
            [
                'cancelled' => $cancelled,
                'playbook' => PlaybookResource::make($playbook->load('steps')),
            ],
            'In-flight enrolments exited successfully.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'kind' => [$creating ? 'required' : 'sometimes', Rule::enum(PlaybookKind::class)],
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:128'],
            'enrolment_filters' => ['sometimes', 'nullable', 'array'],
            'steps' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'array'],
            'steps.*.offset_days' => ['required_with:steps', 'integer', 'min:0'],
            'steps.*.action' => ['required_with:steps', Rule::enum(PlaybookStepAction::class)],
            'steps.*.params' => ['nullable', 'array'],
            'steps.*.sort' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $steps */
    private function syncSteps(Playbook $playbook, array $steps): void
    {
        $playbook->steps()->delete();

        if ($steps === []) {
            return;
        }

        $records = [];
        foreach (array_values($steps) as $index => $step) {
            $records[] = [
                'playbook_id' => $playbook->id,
                'offset_days' => (int) $step['offset_days'],
                'action' => $step['action'] instanceof PlaybookStepAction
                    ? $step['action']->value
                    : (string) $step['action'],
                'params' => json_encode($step['params'] ?? []),
                'sort' => isset($step['sort']) ? (int) $step['sort'] : $index,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        PlaybookStep::insert($records);
    }
}
