<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Payment-provider credentials scoped to a legal entity (merchant of record).
 * secret_key / webhook_secret are encrypted at rest and never serialized raw —
 * see PaymentProviderAccountResource / App\Support\Credentials.
 *
 * account_token is the inbound webhook routing secret (invariant 6); never the PK.
 * provider_account_id is provider metadata (e.g. Stripe acct_…) — never used for routing.
 *
 * @property int              $id
 * @property int              $legal_entity_id
 * @property string           $provider
 * @property string           $display_name
 * @property string|null      $publishable_key
 * @property string|null      $secret_key
 * @property string|null      $webhook_secret
 * @property string|null      $webhook_endpoint_id
 * @property string|null      $provider_account_id
 * @property string           $account_token
 * @property CredentialStatus $status
 * @property string|null      $last_error
 * @property bool             $is_active
 * @property Carbon           $created_at
 * @property Carbon           $updated_at
 *
 * @property-read LegalEntity $legalEntity
 *
 * @property bool $providerAccountMismatch Transient — set by controller on key rotation.
 */
class PaymentProviderAccount extends Model
{
    use HasFactory;

    /** Transient flag for key-rotation account-id mismatch (not persisted). */
    public bool $providerAccountMismatch = false;

    protected $fillable = [
        'legal_entity_id',
        'provider',
        'display_name',
        'publishable_key',
        'secret_key',
        'webhook_secret',
        'webhook_endpoint_id',
        'provider_account_id',
        'account_token',
        'status',
        'last_error',
        'is_active',
    ];

    protected $hidden = [
        'secret_key',
        'webhook_secret',
        'account_token',
    ];

    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'status' => CredentialStatus::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentProviderAccount $account): void {
            if ($account->account_token === null || $account->account_token === '') {
                $account->account_token = Str::random(40);
            }

            if ($account->display_name === null || $account->display_name === '') {
                $account->display_name = 'Stripe';
            }

            if ($account->provider === null || $account->provider === '') {
                $account->provider = 'stripe';
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->status === CredentialStatus::Connected;
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** @return HasMany<StripeWebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(StripeWebhookEvent::class);
    }
}
