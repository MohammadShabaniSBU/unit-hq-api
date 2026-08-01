<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AutopayAttemptStatus;
use App\Enums\ContractNoticeType;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\HoldType;
use App\Enums\LogChannel;
use App\Http\Resources\DelinquencyResource;
use App\Models\AutopayAttempt;
use App\Models\Charge;
use App\Models\ContractNotice;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyEngine;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Delinquency\LateFeeAssessor;
use App\Support\Delinquency\Overlock;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Collections desk: board aggregate, case timeline, manual actions (S07-04).
 */
class DelinquencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'cured'])],
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'contract_id' => ['sometimes', 'integer', 'exists:contracts,id'],
            'paused' => ['sometimes', 'boolean'],
            'overlocked' => ['sometimes', 'boolean'],
            'days_bucket' => ['sometimes', Rule::in(['1-7', '8-14', '15-30', '30+'])],
        ]);

        $status = $validated['status'] ?? 'open';

        $query = Delinquency::query()
            ->with([
                'policy.steps',
                'steps',
                'contract.contact',
                'contract.charges.allocations',
                'contract.payments' => fn ($q) => $q->latest('id')->limit(1),
                'contract.unitItem' => function ($q): void {
                    $q->with(['item' => function (MorphTo $morphTo): void {
                        $morphTo->morphWith([
                            Unit::class => ['site'],
                        ]);
                    }]);
                },
            ])
            ->when($status === 'open', fn (Builder $q) => $q->whereNull('cured_on'))
            ->when($status === 'cured', fn (Builder $q) => $q->whereNotNull('cured_on'))
            ->when(isset($validated['contract_id']), fn (Builder $q) => $q->where('contract_id', $validated['contract_id']))
            ->when(isset($validated['paused']), function (Builder $q) use ($validated): void {
                if ($validated['paused']) {
                    $q->whereNotNull('paused_at')->whereNull('cured_on');
                } else {
                    $q->whereNull('paused_at');
                }
            })
            ->when(isset($validated['site_id']), function (Builder $q) use ($validated): void {
                $siteId = (int) $validated['site_id'];
                $q->whereExists(function ($sub) use ($siteId): void {
                    $sub->selectRaw('1')
                        ->from('contract_items')
                        ->join('units', function ($join): void {
                            $join->on('units.id', '=', 'contract_items.item_id')
                                ->where('contract_items.item_type', '=', 'unit');
                        })
                        ->whereColumn('contract_items.contract_id', 'delinquencies.contract_id')
                        ->whereNull('contract_items.effective_to')
                        ->where('units.site_id', $siteId);
                });
            })
            ->orderByDesc('opened_on')
            ->orderByDesc('id');

        // Chips always reflect open cases (with same site/paused/overlocked/days filters).
        $chipBase = Delinquency::query()
            ->whereNull('cured_on')
            ->with([
                'contract.charges.allocations',
                'contract.unitItem' => function ($q): void {
                    $q->with(['item' => function (MorphTo $morphTo): void {
                        $morphTo->morphWith([Unit::class => ['site']]);
                    }]);
                },
            ])
            ->when(isset($validated['site_id']), function (Builder $q) use ($validated): void {
                $siteId = (int) $validated['site_id'];
                $q->whereExists(function ($sub) use ($siteId): void {
                    $sub->selectRaw('1')
                        ->from('contract_items')
                        ->join('units', function ($join): void {
                            $join->on('units.id', '=', 'contract_items.item_id')
                                ->where('contract_items.item_type', '=', 'unit');
                        })
                        ->whereColumn('contract_items.contract_id', 'delinquencies.contract_id')
                        ->whereNull('contract_items.effective_to')
                        ->where('units.site_id', $siteId);
                });
            })
            ->when(isset($validated['paused']), function (Builder $q) use ($validated): void {
                if ($validated['paused']) {
                    $q->whereNotNull('paused_at');
                } else {
                    $q->whereNull('paused_at');
                }
            })
            ->when(isset($validated['contract_id']), fn (Builder $q) => $q->where('contract_id', $validated['contract_id']));

        $chipCases = $chipBase->get();
        $overlockedCaseIds = $this->overlockedCaseIds($chipCases->pluck('id')->all());

        if (isset($validated['overlocked'])) {
            $want = (bool) $validated['overlocked'];
            $chipCases = $chipCases->filter(function (Delinquency $case) use ($overlockedCaseIds, $want): bool {
                $is = in_array($case->id, $overlockedCaseIds, true);

                return $want ? $is : ! $is;
            })->values();
        }

        if (isset($validated['days_bucket'])) {
            $bucket = $validated['days_bucket'];
            $chipCases = $chipCases->filter(function (Delinquency $case) use ($bucket): bool {
                $days = $case->contract !== null ? DelinquencyState::daysOverdue($case->contract) : null;

                return $this->matchesDaysBucket($days, $bucket);
            })->values();
        }

        $chips = $this->buildChips($chipCases, $overlockedCaseIds);

        // Apply overlocked / days_bucket to the list query via ID filter after load.
        $cases = $query->get();
        $listOverlockedIds = $this->overlockedCaseIds($cases->pluck('id')->all());

        if (isset($validated['overlocked'])) {
            $want = (bool) $validated['overlocked'];
            $cases = $cases->filter(function (Delinquency $case) use ($listOverlockedIds, $want): bool {
                $is = in_array($case->id, $listOverlockedIds, true);

                return $want ? $is : ! $is;
            })->values();
        }

        if (isset($validated['days_bucket'])) {
            $bucket = $validated['days_bucket'];
            $cases = $cases->filter(function (Delinquency $case) use ($bucket): bool {
                $days = $case->contract !== null ? DelinquencyState::daysOverdue($case->contract) : null;

                return $this->matchesDaysBucket($days, $bucket);
            })->values();
        }

        $perPage = $this->perPage();
        $page = max(1, (int) $request->input('page', 1));
        $total = $cases->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $slice = $cases->slice(($page - 1) * $perPage, $perPage)->values();

        $failedAutopayIds = $this->failedAutopayContractIds(
            $slice->pluck('contract_id')->map(fn ($id) => (int) $id)->all()
        );

        $items = $slice->map(function (Delinquency $case) use ($listOverlockedIds, $failedAutopayIds) {
            return DelinquencyResource::make($case)->additional([
                'overlocked' => in_array($case->id, $listOverlockedIds, true),
                'failed_autopay' => in_array((int) $case->contract_id, $failedAutopayIds, true),
            ]);
        });

        return response()->json([
            'message' => 'Delinquencies retrieved successfully.',
            'data' => $items->map(fn ($r) => $r->resolve())->all(),
            'meta' => array_merge([
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ], $chips),
        ]);
    }

    public function show(Delinquency $delinquency): JsonResponse
    {
        $delinquency->load([
            'policy.steps',
            'pausedBy',
            'contract.contact',
            'contract.charges.allocations',
            'contract.payments' => fn ($q) => $q->latest('id')->limit(1),
            'contract.unitItem' => function ($q): void {
                $q->with(['item' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([Unit::class => ['site']]);
                }]);
            },
        ]);

        $timeline = $delinquency->timeline();
        $delinquency->setRelation('steps', $timeline);

        $liveIds = Overlock::liveHolds($delinquency)
            ->pluck('unit_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $failed = in_array(
            (int) $delinquency->contract_id,
            $this->failedAutopayContractIds([(int) $delinquency->contract_id]),
            true,
        );

        return $this->success(
            DelinquencyResource::make($delinquency)->additional([
                'include_timeline' => true,
                'overlocked' => $liveIds !== [],
                'live_overlock_unit_ids' => $liveIds,
                'failed_autopay' => $failed,
            ]),
            'Delinquency retrieved successfully.',
        );
    }

    public function assessFee(Request $request, Delinquency $delinquency): JsonResponse
    {
        $this->assertOpen($delinquency);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();
        $contract = $delinquency->contract()->firstOrFail();

        LateFeeAssessor::assessManual(
            $delinquency,
            $contract,
            BillingMath::round2((string) $validated['amount']),
            $validated['reason'],
            $employee,
        );

        return $this->show($delinquency->fresh() ?? $delinquency);
    }

    public function overlock(Request $request, Delinquency $delinquency): JsonResponse
    {
        $this->assertOpen($delinquency);

        $validated = $request->validate([
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();
        $unitId = isset($validated['unit_id']) ? (int) $validated['unit_id'] : null;

        try {
            $result = Overlock::place($delinquency, $employee, $unitId);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), [], 422);
        }

        $holds = is_array($result) ? $result : [$result];
        /** @var UnitHold $primary */
        $primary = $holds[0];
        $today = DelinquencyState::siteToday($delinquency->contract)->toDateString();

        DelinquencyLifecycle::recordStep(
            delinquency: $delinquency,
            action: DelinquencyStepAction::PlaceOverlock,
            trigger: DelinquencyStepTrigger::Manual,
            executedOn: $today,
            unitHold: $primary,
            detail: [
                'unit_hold_ids' => array_map(fn (UnitHold $h): int => (int) $h->id, $holds),
            ],
            createdBy: $employee,
        );

        return $this->show($delinquency->fresh() ?? $delinquency);
    }

    public function releaseOverlock(Request $request, Delinquency $delinquency): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'reason' => ['sometimes', 'string', 'max:2000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();
        $unitId = isset($validated['unit_id']) ? (int) $validated['unit_id'] : null;
        $reason = $validated['reason'] ?? 'manual';

        Overlock::release($delinquency, $reason, $employee, $unitId);

        return $this->show($delinquency->fresh() ?? $delinquency);
    }

    public function recordNotice(Request $request, Delinquency $delinquency): JsonResponse
    {
        $this->assertOpen($delinquency);

        $validated = $request->validate([
            'notice_type' => ['required', Rule::enum(ContractNoticeType::class)],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();
        $contract = $delinquency->contract()->firstOrFail();
        $today = DelinquencyState::siteToday($contract)->toDateString();

        $notice = ContractNotice::query()->create([
            'contract_id' => $contract->id,
            'notice_type' => $validated['notice_type'],
            'effective_date' => null,
            'required_by' => null,
            'sent_at' => null,
            'sent_channel' => null,
            'sent_to' => null,
            'document_ref' => null,
            'short_notice_reason' => null,
            'contract_item_id' => null,
            'created_by' => $employee->id,
        ]);

        DelinquencyLifecycle::recordStep(
            delinquency: $delinquency,
            action: DelinquencyStepAction::RecordNotice,
            trigger: DelinquencyStepTrigger::Manual,
            executedOn: $today,
            contractNotice: $notice,
            createdBy: $employee,
        );

        return $this->show($delinquency->fresh() ?? $delinquency);
    }

    public function pause(Request $request, Delinquency $delinquency): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();

        try {
            DelinquencyLifecycle::pause($delinquency, $validated['reason'], $employee);
        } catch (InvalidArgumentException $e) {
            return $this->error(__('errors.delinquency.'.$this->lifecycleErrorKey($e)), [], 422);
        }

        return $this->show($delinquency->fresh() ?? $delinquency);
    }

    public function resume(Request $request, Delinquency $delinquency): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        try {
            $wasPaused = DelinquencyLifecycle::resume($delinquency, $employee);
        } catch (InvalidArgumentException $e) {
            return $this->error(__('errors.delinquency.'.$this->lifecycleErrorKey($e)), [], 422);
        }

        if ($wasPaused) {
            $contract = $delinquency->contract()->firstOrFail();
            (new DelinquencyEngine)->evaluateContract($contract, afterPause: true);
        }

        return $this->show($delinquency->fresh() ?? $delinquency);
    }

    public function writeOff(Request $request, Delinquency $delinquency): JsonResponse
    {
        $this->assertOpen($delinquency);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();
        $contract = $delinquency->contract()->with('charges.allocations')->firstOrFail();

        DB::transaction(function () use ($delinquency, $contract, $validated, $employee): void {
            $overdue = DelinquencyState::overdueCharges($contract);
            $total = '0.00';
            foreach ($overdue as $charge) {
                $total = bcadd($total, $charge->openAmount(), 2);
            }
            $total = BillingMath::round2($total);

            if (bccomp($total, '0', 2) <= 0) {
                (new DelinquencyEngine)->evaluateContract($contract, DelinquencyCureTrigger::WriteOff);

                return;
            }

            $today = DelinquencyState::siteToday($contract);
            // due_date in the past so net-overdue includes the write-off.
            $dueDate = $today->subDay()->toDateString();
            $net = BillingMath::round2(bcmul($total, '-1', 2));

            $charge = Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => null,
                'billing_period_id' => null,
                'charge_type' => \App\Enums\ChargeType::WriteOff,
                'period_start' => null,
                'period_end' => null,
                'net_amount' => $net,
                'tax_rate_snapshot' => null,
                'tax_amount' => '0.00',
                'amount' => $net,
                'currency' => $contract->currency,
                'due_date' => $dueDate,
                'description' => 'Write-off: '.$validated['reason'],
            ]);

            DelinquencyLifecycle::recordStep(
                delinquency: $delinquency,
                action: DelinquencyStepAction::WriteOff,
                trigger: DelinquencyStepTrigger::Manual,
                executedOn: $today->toDateString(),
                charge: $charge,
                detail: [
                    'reason' => $validated['reason'],
                    'amount' => $net,
                ],
                createdBy: $employee,
            );

            RecordsActivity::log(
                LogChannel::Billing,
                'delinquency.write_off',
                $delinquency,
                [
                    'reason' => $validated['reason'],
                    'amount' => $net,
                    'charge_id' => $charge->id,
                    'contract_id' => $contract->id,
                ],
                causer: $employee,
            );

            (new DelinquencyEngine)->evaluateContract(
                $contract->fresh() ?? $contract,
                DelinquencyCureTrigger::WriteOff,
            );
        });

        return $this->show($delinquency->fresh() ?? $delinquency);
    }

    private function assertOpen(Delinquency $delinquency): void
    {
        if (! $delinquency->isOpen()) {
            abort(response()->json([
                'message' => __('errors.delinquency.case_not_open'),
            ], 422));
        }
    }

    private function lifecycleErrorKey(InvalidArgumentException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already paused')) {
            return 'already_paused';
        }
        if (str_contains($msg, 'cured')) {
            return 'case_not_open';
        }

        return 'action_failed';
    }

    /**
     * @param  list<int>  $caseIds
     * @return list<int>
     */
    private function overlockedCaseIds(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $reasons = array_map(fn (int $id): string => 'delinquency:'.$id, $caseIds);

        $liveReasons = UnitHold::query()
            ->where('hold_type', HoldType::Overlock)
            ->whereNull('released_at')
            ->whereIn('reason', $reasons)
            ->pluck('reason')
            ->unique()
            ->all();

        $ids = [];
        foreach ($liveReasons as $reason) {
            $id = Overlock::delinquencyIdFromReason((string) $reason);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $contractIds
     * @return list<int>
     */
    private function failedAutopayContractIds(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $latestIds = AutopayAttempt::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('contract_id', $contractIds)
            ->groupBy('contract_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return [];
        }

        return AutopayAttempt::query()
            ->whereIn('id', $latestIds)
            ->where('status', AutopayAttemptStatus::Failed)
            ->pluck('contract_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Delinquency>  $openCases
     * @param  list<int>  $overlockedCaseIds
     * @return array{
     *     open_count: int,
     *     overdue_by_currency: list<array{currency: string, amount: string}>,
     *     overlocked_count: int,
     *     failed_autopay_count: int
     * }
     */
    private function buildChips($openCases, array $overlockedCaseIds): array
    {
        $byCurrency = [];
        foreach ($openCases as $case) {
            $contract = $case->contract;
            if ($contract === null) {
                continue;
            }
            $currency = (string) $contract->currency;
            $total = '0.00';
            foreach (DelinquencyState::overdueCharges($contract) as $charge) {
                $total = bcadd($total, $charge->openAmount(), 2);
            }
            $byCurrency[$currency] = BillingMath::round2(
                bcadd($byCurrency[$currency] ?? '0.00', $total, 2)
            );
        }

        ksort($byCurrency);
        $overdueByCurrency = [];
        foreach ($byCurrency as $currency => $amount) {
            $overdueByCurrency[] = ['currency' => $currency, 'amount' => $amount];
        }

        $contractIds = $openCases->pluck('contract_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $failedCount = count(array_intersect(
            $contractIds,
            $this->failedAutopayContractIds($contractIds),
        ));

        $overlockedCount = $openCases->filter(
            fn (Delinquency $c): bool => in_array($c->id, $overlockedCaseIds, true)
        )->count();

        return [
            'open_count' => $openCases->count(),
            'overdue_by_currency' => $overdueByCurrency,
            'overlocked_count' => $overlockedCount,
            'failed_autopay_count' => $failedCount,
        ];
    }

    private function matchesDaysBucket(?int $days, string $bucket): bool
    {
        if ($days === null || $days < 1) {
            return false;
        }

        return match ($bucket) {
            '1-7' => $days <= 7,
            '8-14' => $days >= 8 && $days <= 14,
            '15-30' => $days >= 15 && $days <= 30,
            '30+' => $days > 30,
            default => false,
        };
    }
}
