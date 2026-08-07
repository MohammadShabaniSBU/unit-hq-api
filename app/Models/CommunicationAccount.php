<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Provider credentials for outbound communications. Either company-scoped
 * (scope=company, site_id null) or site-scoped. Multiple providers may be
 * configured per channel; is_active selects the live one.
 *
 * @property int                          $id
 * @property AccountScope                 $scope
 * @property int|null                     $site_id
 * @property Channel                      $channel
 * @property Provider                     $provider
 * @property bool                         $is_active
 * @property array<string, mixed>|null    $credentials
 * @property string|null                  $webhook_url_token
 * @property string|null                  $webhook_endpoint_id
 * @property Carbon|null                  $webhook_configured_at
 * @property CredentialStatus             $status
 * @property Carbon|null                  $verified_at
 * @property string|null                  $last_error
 * @property Carbon                       $created_at
 * @property Carbon                       $updated_at
 *
 * @property-read Site|null                       $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteSenderIdentity> $senderIdentities
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhatsappTemplate> $whatsappTemplates
 */
class CommunicationAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'site_id',
        'channel',
        'provider',
        'is_active',
        'credentials',
        'webhook_url_token',
        'webhook_endpoint_id',
        'webhook_configured_at',
        'status',
        'verified_at',
        'last_error',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'scope' => AccountScope::class,
            'channel' => Channel::class,
            'provider' => Provider::class,
            'is_active' => 'boolean',
            'credentials' => 'encrypted:array',
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

    /** @return HasMany<WhatsappTemplate> */
    public function whatsappTemplates(): HasMany
    {
        return $this->hasMany(WhatsappTemplate::class);
    }
}
