<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InsightReportSource;
use App\Enums\InsightResourceKind;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightValidationStatus;
use App\Enums\InsightVisibility;
use App\Support\Insights\NativeReports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Registry row for a native or embedded Insights report (S21-03).
 * Archive-only; system native rows may be archived/unarchived but never repointed.
 *
 * @property int                     $id
 * @property string                  $key
 * @property InsightReportSource     $source
 * @property string|null             $native_key
 * @property int|null                $analytics_account_id
 * @property InsightResourceKind|null $resource_kind
 * @property string|null             $resource_ref
 * @property array<string, string>|null $labels
 * @property array<string, string>|null $description
 * @property string|null             $icon
 * @property string|null             $section
 * @property int                     $sort_order
 * @property InsightVisibility       $visibility
 * @property InsightSiteScopeMode    $site_scope_mode
 * @property array<string, mixed>    $options
 * @property bool                    $is_system
 * @property Carbon|null             $archived_at
 * @property Carbon|null             $last_validated_at
 * @property InsightValidationStatus $validation_status
 * @property array<string, mixed>|null $validation_detail
 * @property int|null                $created_by
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 *
 * @property-read AnalyticsAccount|null $analyticsAccount
 * @property-read Employee|null $createdBy
 * @property-read Collection<int, InsightReportParam> $params
 */
class InsightReport extends Model
{
    protected $fillable = [
        'key',
        'source',
        'native_key',
        'analytics_account_id',
        'resource_kind',
        'resource_ref',
        'labels',
        'description',
        'icon',
        'section',
        'sort_order',
        'visibility',
        'site_scope_mode',
        'options',
        'is_system',
        'archived_at',
        'last_validated_at',
        'validation_status',
        'validation_detail',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => InsightReportSource::class,
            'resource_kind' => InsightResourceKind::class,
            'labels' => 'array',
            'description' => 'array',
            'options' => 'array',
            'visibility' => InsightVisibility::class,
            'site_scope_mode' => InsightSiteScopeMode::class,
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
            'last_validated_at' => 'datetime',
            'validation_status' => InsightValidationStatus::class,
            'validation_detail' => 'array',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /**
     * Visibility filter stub (I5). Non-`all` values are accepted/stored/ignored
     * this sprint — when RBAC lands, change only this method.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Employee $employee = null): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * I4 — one resolver for operator JSONB labels and native i18n keys.
     * Never mixes a half-resolved string with an i18n key.
     *
     * @return array{label: string, source: 'operator'|'i18n'}
     */
    public function resolveLabel(string $locale): array
    {
        if (is_array($this->labels) && $this->labels !== []) {
            $label = $this->labels[$locale]
                ?? $this->labels['en']
                ?? array_values($this->labels)[0];

            return [
                'label' => (string) $label,
                'source' => 'operator',
            ];
        }

        $nativeKey = $this->native_key ?? $this->key;
        $entry = NativeReports::get($nativeKey);

        return [
            'label' => $entry['label_key'] ?? ('insights.reports.'.str_replace('-', '_', $nativeKey).'.label'),
            'source' => 'i18n',
        ];
    }

    /** @return BelongsTo<AnalyticsAccount, $this> */
    public function analyticsAccount(): BelongsTo
    {
        return $this->belongsTo(AnalyticsAccount::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return HasMany<InsightReportParam, $this> */
    public function params(): HasMany
    {
        return $this->hasMany(InsightReportParam::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<InsightProvisionedResource, $this> */
    public function provisionedResources(): HasMany
    {
        return $this->hasMany(InsightProvisionedResource::class);
    }
}
