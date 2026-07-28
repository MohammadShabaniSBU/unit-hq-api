<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-site Stripe keys. The site is the merchant of record for direct
 * charges into its own Stripe account (no Connect, no application fees, no
 * mode column). secret_key / webhook_secret are encrypted at rest and never
 * serialized raw — see SiteStripeSettingResource / App\Support\Credentials.
 *
 * @property int          $id
 * @property int          $site_id
 * @property string|null  $publishable_key
 * @property string|null  $secret_key
 * @property string|null  $webhook_secret
 * @property string|null  $webhook_endpoint_id
 * @property string|null  $webhook_route_token
 * @property CredentialStatus $status
 * @property Carbon|null  $verified_at
 * @property string|null  $last_error
 * @property Carbon       $created_at
 * @property Carbon       $updated_at
 *
 * @property-read Site $site
 */
class SiteStripeSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'publishable_key',
        'secret_key',
        'webhook_secret',
        'webhook_endpoint_id',
        'webhook_route_token',
        'status',
        'verified_at',
        'last_error',
    ];

    protected $hidden = [
        'secret_key',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
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
}
