<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessSuspensionLiftReason;
use App\Enums\AccessSuspensionReason;
use App\Support\Access\AccessSync;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Total access deny for a contract. Lift never deletes (S10 idiom).
 *
 * @property int                              $id
 * @property int                              $contract_id
 * @property AccessSuspensionReason           $reason
 * @property int|null                         $delinquency_id
 * @property int|null                         $created_by
 * @property Carbon|null                      $lifted_at
 * @property int|null                         $lifted_by
 * @property AccessSuspensionLiftReason|null  $lift_reason
 * @property Carbon                           $created_at
 *
 * @property-read Contract                    $contract
 * @property-read Delinquency|null            $delinquency
 * @property-read Employee|null               $createdBy
 */
class AccessSuspension extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'contract_id',
        'reason',
        'delinquency_id',
        'created_by',
        'lifted_at',
        'lifted_by',
        'lift_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => AccessSuspensionReason::class,
            'lift_reason' => AccessSuspensionLiftReason::class,
            'lifted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contract, AccessSuspension> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Delinquency, AccessSuspension> */
    public function delinquency(): BelongsTo
    {
        return $this->belongsTo(Delinquency::class);
    }

    /** @return BelongsTo<Employee, AccessSuspension> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @param  Builder<AccessSuspension>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }

    /**
     * Idempotent: one active suspension per contract (partial unique).
     */
    public static function suspend(
        Contract $contract,
        AccessSuspensionReason $reason,
        ?Delinquency $case = null,
        ?Employee $by = null,
    ): self {
        $contractId = (int) $contract->id;
        $created = false;

        $suspension = DB::transaction(function () use ($reason, $case, $by, $contractId, &$created): self {
            $existing = self::query()
                ->active()
                ->where('contract_id', $contractId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                $row = self::query()->create([
                    'contract_id' => $contractId,
                    'reason' => $reason,
                    'delinquency_id' => $case?->id,
                    'created_by' => $by?->id,
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return self::query()
                    ->active()
                    ->where('contract_id', $contractId)
                    ->firstOrFail();
            }

            RecordsActivity::core('access.suspended', $row, [
                'reason' => $reason->value,
                'contract_id' => $contractId,
                'delinquency_id' => $case?->id,
            ], causer: $by);

            $created = true;

            return $row;
        });

        if ($created) {
            DB::afterCommit(static function () use ($contractId): void {
                AccessSync::nudge($contractId);
            });
        }

        return $suspension;
    }

    /**
     * Lift the active suspension. Never deletes. Idempotent when none active.
     */
    public static function lift(
        Contract $contract,
        AccessSuspensionLiftReason $reason,
        ?Employee $by = null,
    ): ?self {
        $contractId = (int) $contract->id;

        $lifted = DB::transaction(function () use ($reason, $by, $contractId): ?self {
            $active = self::query()
                ->active()
                ->where('contract_id', $contractId)
                ->lockForUpdate()
                ->first();

            if ($active === null) {
                return null;
            }

            $active->forceFill([
                'lifted_at' => now(),
                'lifted_by' => $by?->id,
                'lift_reason' => $reason,
            ])->save();

            RecordsActivity::core('access.lifted', $active, [
                'lift_reason' => $reason->value,
                'contract_id' => $contractId,
            ], causer: $by);

            return $active->refresh();
        });

        if ($lifted !== null) {
            DB::afterCommit(static function () use ($contractId): void {
                AccessSync::nudge($contractId);
            });
        }

        return $lifted;
    }
}
