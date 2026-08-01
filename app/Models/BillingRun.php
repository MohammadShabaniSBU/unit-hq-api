<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingRunTrigger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of the recurring billing job. Append-only — finished once,
 * never updated for business corrections (new run = retry).
 *
 * @property int                     $id
 * @property Carbon                  $started_at
 * @property Carbon|null             $finished_at
 * @property BillingRunTrigger       $trigger
 * @property string                  $horizon_date Y-m-d
 * @property int                     $contracts_considered
 * @property int                     $contracts_billed
 * @property int                     $contracts_skipped
 * @property int                     $contracts_failed
 * @property int|null                $created_by
 * @property Carbon                  $created_at
 *
 * @property-read Employee|null                     $createdBy
 * @property-read Collection<int, BillingRunItem>   $items
 */
class BillingRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'started_at',
        'finished_at',
        'trigger',
        'horizon_date',
        'contracts_considered',
        'contracts_billed',
        'contracts_skipped',
        'contracts_failed',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => BillingRunTrigger::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'horizon_date' => 'date',
            'contracts_considered' => 'integer',
            'contracts_billed' => 'integer',
            'contracts_skipped' => 'integer',
            'contracts_failed' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, BillingRun> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return HasMany<BillingRunItem, BillingRun> */
    public function items(): HasMany
    {
        return $this->hasMany(BillingRunItem::class);
    }
}
