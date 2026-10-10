<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use Database\Factories\CampaignRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contact on one campaign send. Unique per send and contact.
 *
 * @property int $id
 * @property int $campaign_send_id
 * @property int $contact_id
 * @property string|null $address
 * @property int|null $site_id
 * @property CampaignRecipientStatus $status
 * @property CampaignSkipReason|null $skip_reason
 * @property int|null $message_id
 * @property Carbon|null $claimed_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CampaignSend $campaignSend
 * @property-read Contact $contact
 * @property-read Site|null $site
 * @property-read Message|null $message
 */
class CampaignRecipient extends Model
{
    /** @use HasFactory<CampaignRecipientFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_send_id',
        'contact_id',
        'address',
        'site_id',
        'status',
        'skip_reason',
        'message_id',
        'claimed_at',
        'processed_at',
        'error',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignRecipientStatus::class,
            'skip_reason' => CampaignSkipReason::class,
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CampaignSend, $this> */
    public function campaignSend(): BelongsTo
    {
        return $this->belongsTo(CampaignSend::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
