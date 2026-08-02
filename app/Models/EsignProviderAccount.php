<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Company-wide e-sign provider credentials (S14-02).
 * One active account per provider; v1 one active provider per install.
 * webhook_token is the inbound routing secret (invariant 6); never the PK.
 *
 * @property int               $id
 * @property EsignProvider     $provider
 * @property string            $display_name
 * @property array             $credentials
 * @property string            $webhook_token
 * @property EsignWebhookState $webhook_state
 * @property array|null        $webhook_endpoint_ids
 * @property CredentialStatus  $status
 * @property string|null       $last_error
 * @property bool              $is_active
 * @property Carbon            $created_at
 * @property Carbon            $updated_at
 */
class EsignProviderAccount extends Model
{
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
    ];

    protected $hidden = [
        'credentials',
        'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'provider' => EsignProvider::class,
            'credentials' => 'encrypted:array',
            'webhook_state' => EsignWebhookState::class,
            'webhook_endpoint_ids' => 'array',
            'status' => CredentialStatus::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EsignProviderAccount $account): void {
            if ($account->webhook_token === null || $account->webhook_token === '') {
                $account->webhook_token = Str::random(40);
            }

            if ($account->display_name === null || $account->display_name === '') {
                $account->display_name = $account->provider?->label() ?? 'E-signature';
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->status === CredentialStatus::Connected;
    }

    /** @return HasMany<EsignWebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(EsignWebhookEvent::class);
    }
}
