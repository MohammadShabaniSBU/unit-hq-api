<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Company-wide access provider credentials (S15-01).
 * One active account per provider; v1 one active provider per install.
 * webhook_token is the inbound routing secret (invariant 6); never the PK.
 *
 * @property int                     $id
 * @property AccessProviderName      $provider
 * @property string                  $display_name
 * @property array                   $credentials
 * @property string                  $webhook_token
 * @property AccessWebhookState      $webhook_state
 * @property array|null              $webhook_endpoint_ids
 * @property CredentialStatus        $status
 * @property string|null             $last_error
 * @property bool                    $is_active
 * @property array|null              $discovered_points
 * @property Carbon|null             $points_discovered_at
 * @property Carbon|null             $last_full_synced_at
 * @property array|null              $sync_attention
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 */
class AccessProviderAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'display_name',
        'credentials',
        'webhook_token',
        'webhook_state',
        'webhook_endpoint_ids',
        'status',
        'last_error',
        'is_active',
        'discovered_points',
        'points_discovered_at',
        'last_full_synced_at',
        'sync_attention',
    ];

    protected $hidden = [
        'credentials',
        'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AccessProviderName::class,
            'credentials' => 'encrypted:array',
            'webhook_state' => AccessWebhookState::class,
            'webhook_endpoint_ids' => 'array',
            'status' => CredentialStatus::class,
            'is_active' => 'boolean',
            'discovered_points' => 'array',
            'points_discovered_at' => 'datetime',
            'last_full_synced_at' => 'datetime',
            'sync_attention' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AccessProviderAccount $account): void {
            if ($account->webhook_token === null || $account->webhook_token === '') {
                $account->webhook_token = Str::random(40);
            }

            if ($account->display_name === null || $account->display_name === '') {
                $account->display_name = $account->provider?->label() ?? 'Access control';
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->status === CredentialStatus::Connected;
    }

    /** @return HasMany<AccessWebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(AccessWebhookEvent::class);
    }

    /** @return HasMany<AccessPoint, $this> */
    public function accessPoints(): HasMany
    {
        return $this->hasMany(AccessPoint::class);
    }

    public function discoveredPointsCount(): int
    {
        $points = $this->discovered_points;

        return is_array($points) ? count($points) : 0;
    }
}
