<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiProvider;
use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Company-scoped AI provider credentials, restricting which models the
 * Copilot may use. Archive-only; at most one non-archived default account.
 *
 * @property int              $id
 * @property AiProvider       $provider
 * @property string           $display_name
 * @property array            $credentials
 * @property list<string>     $allowed_models
 * @property string|null      $default_model
 * @property bool             $is_default
 * @property CredentialStatus $connection_status
 * @property string|null      $last_error
 * @property Carbon|null      $last_verified_at
 * @property Carbon|null      $archived_at
 * @property int|null         $created_by
 * @property Carbon           $created_at
 * @property Carbon           $updated_at
 *
 * @property-read Employee|null $createdBy
 */
class AiProviderAccount extends Model
{
    protected $fillable = [
        'provider',
        'display_name',
        'credentials',
        'allowed_models',
        'default_model',
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
            'provider' => AiProvider::class,
            'credentials' => 'encrypted:array',
            'allowed_models' => 'array',
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

    /** @return BelongsTo<Employee, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
