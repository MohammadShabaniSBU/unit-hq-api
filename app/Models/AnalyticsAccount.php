<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Company-scoped analytics provider credentials (S21-02).
 * Archive-only; at most one non-archived default account.
 *
 * @property int               $id
 * @property AnalyticsProvider $provider
 * @property string            $display_name
 * @property string            $base_url
 * @property string|null       $private_base_url
 * @property array             $credentials
 * @property bool              $is_default
 * @property CredentialStatus  $connection_status
 * @property string|null       $last_error
 * @property Carbon|null       $last_verified_at
 * @property Carbon|null       $archived_at
 * @property int|null          $created_by
 * @property Carbon            $created_at
 * @property Carbon            $updated_at
 *
 * @property-read Employee|null $createdBy
 */
class AnalyticsAccount extends Model
{
    protected $fillable = [
        'provider',
        'display_name',
        'base_url',
        'private_base_url',
        'credentials',
        'is_default',
        'connection_status',
        'last_error',
        'last_verified_at',
        'archived_at',
        'created_by',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AnalyticsProvider::class,
            'credentials' => 'encrypted:array',
            'is_default' => 'boolean',
            'connection_status' => CredentialStatus::class,
            'last_verified_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true)->whereNull('archived_at');
    }

    public function isConnected(): bool
    {
        return $this->connection_status === CredentialStatus::Connected;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function hasLiveReports(): bool
    {
        return $this->reports()->whereNull('archived_at')->exists();
    }

    /** @return HasMany<InsightReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(InsightReport::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
