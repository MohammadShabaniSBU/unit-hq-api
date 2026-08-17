<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InsightResourceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bookkeeping for a shipped Metabase blueprint. Provider calls are not
 * transactional; this row plus definition_hash makes re-runs idempotent.
 *
 * @property int                     $id
 * @property string                  $blueprint_key
 * @property int                     $analytics_account_id
 * @property int|null                $insight_report_id
 * @property InsightResourceKind     $resource_kind
 * @property string                  $resource_ref
 * @property array<string, mixed>    $card_refs
 * @property string                  $definition_hash
 * @property Carbon                  $provisioned_at
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 *
 * @property-read AnalyticsAccount   $analyticsAccount
 * @property-read InsightReport|null $insightReport
 */
class InsightProvisionedResource extends Model
{
    protected $fillable = [
        'blueprint_key',
        'analytics_account_id',
        'insight_report_id',
        'resource_kind',
        'resource_ref',
        'card_refs',
        'definition_hash',
        'provisioned_at',
    ];

    protected function casts(): array
    {
        return [
            'resource_kind' => InsightResourceKind::class,
            'card_refs' => 'array',
            'provisioned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AnalyticsAccount, $this> */
    public function analyticsAccount(): BelongsTo
    {
        return $this->belongsTo(AnalyticsAccount::class);
    }

    /** @return BelongsTo<InsightReport, $this> */
    public function insightReport(): BelongsTo
    {
        return $this->belongsTo(InsightReport::class);
    }
}
