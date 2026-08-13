<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\SummaryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Regenerable AI summary of a Contact or Deal (invariant 53).
 *
 * Status is mutable (queued → running → succeeded|failed). The body is never
 * edited in place: a successful regeneration inserts a new row and stamps
 * superseded_at on the previous current row inside one transaction. Failed /
 * in-flight rows never supersede a succeeded summary.
 *
 * Success write order (enforced by GenerateAiSummary): stamp superseded_at on
 * the previous current row before flipping this row to succeeded, otherwise
 * the current partial unique fires.
 *
 * @property int $id
 * @property string $summarizable_type
 * @property int $summarizable_id
 * @property SummaryStatus $status
 * @property string|null $body
 * @property array|null $highlights
 * @property string $locale
 * @property string|null $provider
 * @property string|null $model
 * @property string $prompt_version
 * @property string|null $source_digest
 * @property array|null $source_counts
 * @property int|null $ai_usage_event_id
 * @property string|null $error_code
 * @property int|null $requested_by_employee_id
 * @property Carbon|null $generated_at
 * @property Carbon|null $superseded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model                    $summarizable
 * @property-read AiUsageEvent|null        $usageEvent
 * @property-read Employee|null            $requestedBy
 */
class AiSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'summarizable_type',
        'summarizable_id',
        'status',
        'body',
        'highlights',
        'locale',
        'provider',
        'model',
        'prompt_version',
        'source_digest',
        'source_counts',
        'ai_usage_event_id',
        'error_code',
        'requested_by_employee_id',
        'generated_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SummaryStatus::class,
            'highlights' => 'array',
            'source_counts' => 'array',
            'generated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function summarizable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<AiUsageEvent, $this> */
    public function usageEvent(): BelongsTo
    {
        return $this->belongsTo(AiUsageEvent::class, 'ai_usage_event_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by_employee_id');
    }

    /**
     * @param  Builder<AiSummary>  $query
     * @return Builder<AiSummary>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->whereNull('superseded_at')
            ->where('status', SummaryStatus::Succeeded);
    }

    /**
     * @param  Builder<AiSummary>  $query
     * @return Builder<AiSummary>
     */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SummaryStatus::Queued,
            SummaryStatus::Running,
        ]);
    }
}
