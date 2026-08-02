<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DelinquencyPolicyAction;
use App\Http\Resources\DelinquencyPolicyResource;
use App\Models\DelinquencyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DelinquencyPolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = DelinquencyPolicy::query()
            ->with('steps')
            ->withCount('sites')
            ->orderBy('name');

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->success([
            'policies' => DelinquencyPolicyResource::collection($query->get())->resolve(),
            'fiscal' => [
                'late_fee_tax' => (string) config('fiscal.late_fee_tax', '0.00'),
                'invoice_late_fees' => (bool) config('fiscal.invoice_late_fees', false),
            ],
        ], 'Delinquency policies retrieved successfully.');
    }

    public function options(): JsonResponse
    {
        $options = DelinquencyPolicy::query()->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (DelinquencyPolicy $policy) => [
                'value' => $policy->id,
                'label' => $policy->name,
            ]);

        return $this->success($options, 'Delinquency policy options retrieved successfully.');
    }

    public function show(DelinquencyPolicy $delinquencyPolicy): JsonResponse
    {
        $delinquencyPolicy->load('steps')->loadCount('sites');

        return $this->success(
            DelinquencyPolicyResource::make($delinquencyPolicy),
            'Delinquency policy retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request, creating: true);

        $policy = DB::transaction(function () use ($validated) {
            $policy = DelinquencyPolicy::query()->create([
                'name' => $validated['name'],
                'auto_release_overlock' => $validated['auto_release_overlock'] ?? true,
                'auto_restore_access' => $validated['auto_restore_access'] ?? true,
            ]);

            $this->syncSteps($policy, $validated['steps']);

            return $policy->fresh()->load('steps')->loadCount('sites');
        });

        return $this->created(
            DelinquencyPolicyResource::make($policy),
            'Delinquency policy created successfully.'
        );
    }

    public function update(Request $request, DelinquencyPolicy $delinquencyPolicy): JsonResponse
    {
        $validated = $this->validatedPayload($request, creating: false);

        $policy = DB::transaction(function () use ($delinquencyPolicy, $validated) {
            $attributes = [];
            if (array_key_exists('name', $validated)) {
                $attributes['name'] = $validated['name'];
            }
            if (array_key_exists('auto_release_overlock', $validated)) {
                $attributes['auto_release_overlock'] = $validated['auto_release_overlock'];
            }
            if (array_key_exists('auto_restore_access', $validated)) {
                $attributes['auto_restore_access'] = $validated['auto_restore_access'];
            }
            if ($attributes !== []) {
                $delinquencyPolicy->update($attributes);
            }

            if (array_key_exists('steps', $validated)) {
                $this->syncSteps($delinquencyPolicy, $validated['steps']);
            }

            return $delinquencyPolicy->fresh()->load('steps')->loadCount('sites');
        });

        return $this->success(
            DelinquencyPolicyResource::make($policy),
            'Delinquency policy updated successfully.'
        );
    }

    public function archive(DelinquencyPolicy $delinquencyPolicy): JsonResponse
    {
        if ($delinquencyPolicy->isArchived()) {
            return $this->success(
                DelinquencyPolicyResource::make($delinquencyPolicy->load('steps')->loadCount('sites')),
                'Delinquency policy already archived.'
            );
        }

        $this->assertCanArchive($delinquencyPolicy);

        $delinquencyPolicy->update(['archived_at' => now()]);

        return $this->success(
            DelinquencyPolicyResource::make($delinquencyPolicy->fresh()->load('steps')->loadCount('sites')),
            'Delinquency policy archived successfully.'
        );
    }

    public function unarchive(DelinquencyPolicy $delinquencyPolicy): JsonResponse
    {
        if (! $delinquencyPolicy->isArchived()) {
            return $this->success(
                DelinquencyPolicyResource::make($delinquencyPolicy->load('steps')->loadCount('sites')),
                'Delinquency policy already active.'
            );
        }

        $delinquencyPolicy->update(['archived_at' => null]);

        return $this->success(
            DelinquencyPolicyResource::make($delinquencyPolicy->fresh()->load('steps')->loadCount('sites')),
            'Delinquency policy restored successfully.'
        );
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $creating): array
    {
        $validated = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:128'],
            'auto_release_overlock' => ['sometimes', 'boolean'],
            'auto_restore_access' => ['sometimes', 'boolean'],
            'steps' => [$creating ? 'required' : 'sometimes', 'required', 'array', 'min:1'],
            'steps.*.offset_days' => ['required', 'integer', 'min:0'],
            'steps.*.action' => ['required', 'string', Rule::in(DelinquencyPolicyAction::values())],
            'steps.*.params' => ['sometimes', 'array'],
            'steps.*.sort' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (isset($validated['steps'])) {
            $validated['steps'] = $this->normalizeAndValidateSteps($validated['steps']);
        }

        return $validated;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{offset_days: int, action: string, params: array<string, mixed>, sort: int}>
     */
    private function normalizeAndValidateSteps(array $steps): array
    {
        $normalized = [];
        $seenOffsetAction = [];
        $seenSort = [];

        foreach (array_values($steps) as $index => $step) {
            $actionValue = (string) $step['action'];
            $action = DelinquencyPolicyAction::from($actionValue);

            $offset = (int) $step['offset_days'];
            $sort = array_key_exists('sort', $step) ? (int) $step['sort'] : $index;

            $pairKey = $offset.'|'.$actionValue;
            if (isset($seenOffsetAction[$pairKey])) {
                throw ValidationException::withMessages([
                    "steps.{$index}.action" => [__('errors.delinquency.offset_action_unique')],
                ]);
            }
            $seenOffsetAction[$pairKey] = true;

            if (isset($seenSort[$sort])) {
                throw ValidationException::withMessages([
                    "steps.{$index}.sort" => [__('errors.delinquency.sort_unique')],
                ]);
            }
            $seenSort[$sort] = true;

            $params = is_array($step['params'] ?? null) ? $step['params'] : [];
            $params = $this->validateParams($action, $params, $index);

            $normalized[] = [
                'offset_days' => $offset,
                'action' => $actionValue,
                'params' => $params,
                'sort' => $sort,
            ];
        }

        usort($normalized, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validateParams(DelinquencyPolicyAction $action, array $params, int $index): array
    {
        $prefix = "steps.{$index}.params";

        return match ($action) {
            DelinquencyPolicyAction::AssessLateFee => $this->validateAssessLateFeeParams($params, $prefix),
            DelinquencyPolicyAction::PlaceOverlock => $this->rejectUnknownKeys($params, [], $prefix),
            DelinquencyPolicyAction::RecordNotice => $this->validateRecordNoticeParams($params, $prefix),
            DelinquencyPolicyAction::CreateTask => $this->validateCreateTaskParams($params, $prefix),
            DelinquencyPolicyAction::RevokeAccess => $this->rejectUnknownKeys($params, [], $prefix),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validateAssessLateFeeParams(array $params, string $prefix): array
    {
        $allowed = ['type', 'amount', 'percent', 'cap_per_case'];
        $this->rejectUnknownKeys($params, $allowed, $prefix);

        $type = $params['type'] ?? null;
        if (! in_array($type, ['flat', 'percent'], true)) {
            throw ValidationException::withMessages([
                "{$prefix}.type" => ['The params.type must be flat or percent.'],
            ]);
        }

        $out = ['type' => $type];

        if ($type === 'flat') {
            if (! isset($params['amount']) || ! is_string($params['amount']) && ! is_numeric($params['amount'])) {
                throw ValidationException::withMessages([
                    "{$prefix}.amount" => ['The params.amount field is required for flat late fees.'],
                ]);
            }
            $amount = (string) $params['amount'];
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                throw ValidationException::withMessages([
                    "{$prefix}.amount" => ['The params.amount must be a money string.'],
                ]);
            }
            $out['amount'] = $amount;
            if (isset($params['percent'])) {
                throw ValidationException::withMessages([
                    "{$prefix}.percent" => ['The params.percent field is not allowed for flat late fees.'],
                ]);
            }
        } else {
            if (! isset($params['percent']) || ! is_string($params['percent']) && ! is_numeric($params['percent'])) {
                throw ValidationException::withMessages([
                    "{$prefix}.percent" => ['The params.percent field is required for percent late fees.'],
                ]);
            }
            $percent = (string) $params['percent'];
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $percent)) {
                throw ValidationException::withMessages([
                    "{$prefix}.percent" => ['The params.percent must be a money string.'],
                ]);
            }
            $out['percent'] = $percent;
            if (isset($params['amount'])) {
                throw ValidationException::withMessages([
                    "{$prefix}.amount" => ['The params.amount field is not allowed for percent late fees.'],
                ]);
            }
        }

        if (isset($params['cap_per_case'])) {
            $cap = (string) $params['cap_per_case'];
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $cap)) {
                throw ValidationException::withMessages([
                    "{$prefix}.cap_per_case" => ['The params.cap_per_case must be a money string.'],
                ]);
            }
            $out['cap_per_case'] = $cap;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validateRecordNoticeParams(array $params, string $prefix): array
    {
        $this->rejectUnknownKeys($params, ['notice_type'], $prefix);

        $noticeType = $params['notice_type'] ?? null;
        $allowed = ['payment_reminder', 'overdue', 'final_demand', 'retention'];
        if (! in_array($noticeType, $allowed, true)) {
            throw ValidationException::withMessages([
                "{$prefix}.notice_type" => ['The params.notice_type is invalid.'],
            ]);
        }

        return ['notice_type' => $noticeType];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validateCreateTaskParams(array $params, string $prefix): array
    {
        $this->rejectUnknownKeys($params, ['title_key', 'urgent'], $prefix);

        $titleKey = $params['title_key'] ?? null;
        if (! is_string($titleKey) || $titleKey === '') {
            throw ValidationException::withMessages([
                "{$prefix}.title_key" => ['The params.title_key field is required.'],
            ]);
        }

        if (! array_key_exists('urgent', $params) || ! is_bool($params['urgent'])) {
            throw ValidationException::withMessages([
                "{$prefix}.urgent" => ['The params.urgent field must be a boolean.'],
            ]);
        }

        return [
            'title_key' => $titleKey,
            'urgent' => $params['urgent'],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function rejectUnknownKeys(array $params, array $allowed, string $prefix): array
    {
        $unknown = array_diff(array_keys($params), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $prefix => ['Unknown param keys: '.implode(', ', $unknown).'.'],
            ]);
        }

        return $params;
    }

    /**
     * @param  list<array{offset_days: int, action: string, params: array<string, mixed>, sort: int}>  $steps
     */
    private function syncSteps(DelinquencyPolicy $policy, array $steps): void
    {
        $policy->steps()->delete();

        foreach ($steps as $step) {
            $policy->steps()->create($step);
        }
    }

    private function assertCanArchive(DelinquencyPolicy $policy): void
    {
        $count = $policy->sites()->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'delinquency_policy' => [
                    __('errors.delinquency.archive_in_use', ['count' => $count]),
                ],
            ]);
        }
    }
}
