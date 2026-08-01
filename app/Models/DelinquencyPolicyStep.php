<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DelinquencyPolicyAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rung on a delinquency policy ladder.
 *
 * @property int                     $id
 * @property int                     $delinquency_policy_id
 * @property int                     $offset_days
 * @property DelinquencyPolicyAction $action
 * @property array<string, mixed>    $params
 * @property int                     $sort
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 *
 * @property-read DelinquencyPolicy $policy
 */
class DelinquencyPolicyStep extends Model
{
    protected $fillable = [
        'delinquency_policy_id',
        'offset_days',
        'action',
        'params',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'action' => DelinquencyPolicyAction::class,
            'params' => 'array',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<DelinquencyPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(DelinquencyPolicy::class, 'delinquency_policy_id');
    }
}
