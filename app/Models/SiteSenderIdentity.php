<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommunicationProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-site "from" identity for a comms provider (name/email/number). No
 * secrets — the provider credential lives on the related CommunicationAccount.
 *
 * @property int                       $id
 * @property int                       $site_id
 * @property CommunicationProviderType $provider_type
 * @property int|null                  $account_id
 * @property string|null               $from_name
 * @property string|null               $from_email
 * @property string|null               $from_number
 * @property string|null               $reply_to_email
 * @property string|null               $provider_sender_id
 * @property Carbon|null               $verified_at
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 *
 * @property-read Site                     $site
 * @property-read CommunicationAccount|null $account
 */
class SiteSenderIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'provider_type',
        'account_id',
        'from_name',
        'from_email',
        'from_number',
        'reply_to_email',
        'provider_sender_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_type' => CommunicationProviderType::class,
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<CommunicationAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CommunicationAccount::class, 'account_id');
    }
}
