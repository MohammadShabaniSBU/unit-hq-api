<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InsightParamBinding;
use App\Enums\InsightParamValueSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Param binding for an insight report (S21-03).
 * Dynamic params are always locked (DB CHECK + I2).
 *
 * @property int                     $id
 * @property int                     $insight_report_id
 * @property string                  $name
 * @property InsightParamValueSource $value_source
 * @property mixed                   $static_value
 * @property string|null             $dynamic_key
 * @property InsightParamBinding     $binding
 * @property bool                    $is_required
 * @property int                     $sort_order
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 *
 * @property-read InsightReport $report
 */
class InsightReportParam extends Model
{
    protected $fillable = [
        'insight_report_id',
        'name',
        'value_source',
        'static_value',
        'dynamic_key',
        'binding',
        'is_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'value_source' => InsightParamValueSource::class,
            'static_value' => 'json',
            'binding' => InsightParamBinding::class,
            'is_required' => 'boolean',
        ];
    }

    /** @return BelongsTo<InsightReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(InsightReport::class, 'insight_report_id');
    }
}
