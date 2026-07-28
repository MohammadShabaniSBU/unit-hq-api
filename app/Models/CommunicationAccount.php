<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommunicationAccountScope;
use App\Enums\CommunicationProviderType;
use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Provider credentials for outbound communications. Either company-scoped
 * (one per provider_type, site_id null) or site-scoped (one per site per
 * provider_type). api_key is encrypted at rest and never serialized raw —
 * see CommunicationAccountResource / App\Support\Credentials.
 *
 * @property int                          $id
 * @property CommunicationAccountScope    $scope
 * @property int|null                     $site_id
 * @property CommunicationProviderType    $provider_type
 * @property string|null                  $api_key
 * @property string|null                  $webhook_url_token
 * @property string|null                  $webhook_provider_id
 * @property Carbon|null                  $webhook_configured_at
 * @property CredentialStatus             $status
 * @property Carbon|null                  $verified_at
 * @property string|null                  $last_error
 * @property Carbon                       $created_at
 * @property Carbon                       $updated_at
 *
 * @property-read Site|null                       $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteSenderIdentity> $senderIdentities
 */
class CommunicationAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'site_id',
        'provider_type',
        'api_key',
        'webhook_url_token',
        'webhook_provider_id',
        'webhook_configured_at',
        'status',
        'verified_at',
        'last_error',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'scope' => CommunicationAccountScope::class,
            'provider_type' => CommunicationProviderType::class,
            'api_key' => 'encrypted',
            'webhook_configured_at' => 'datetime',
            'status' => CredentialStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === CredentialStatus::Connected;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<SiteSenderIdentity> */
    public function senderIdentities(): HasMany
    {
        return $this->hasMany(SiteSenderIdentity::class, 'account_id');
    }
}
