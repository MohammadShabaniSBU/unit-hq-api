<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Enums\TemplateVersionStatus;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\CampaignImmutable;
use Database\Factories\CampaignSendFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One channel of a campaign. Pins a template version at schedule time.
 *
 * Once template_version_id is set, family, channel, and subject stay fixed.
 * The pin itself may move only while the campaign is pending_approval:
 * clear it, or point it at another published version of the same family.
 *
 * @property int $id
 * @property int $campaign_id
 * @property Channel $channel
 * @property int $template_family_id
 * @property int|null $template_version_id
 * @property string|null $subject_override
 * @property Carbon|null $scheduled_at
 * @property CampaignSendStatus $status
 * @property Carbon|null $materialised_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Campaign $campaign
 * @property-read TemplateFamily $templateFamily
 * @property-read TemplateVersion|null $templateVersion
 * @property-read Collection<int, CampaignRecipient> $recipients
 */
class CampaignSend extends Model
{
    /** @use HasFactory<CampaignSendFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'channel',
        'template_family_id',
        'template_version_id',
        'subject_override',
        'scheduled_at',
        'status',
        'materialised_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => CampaignSendStatus::class,
            'scheduled_at' => 'datetime',
            'materialised_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $send): void {
            if ($send->getOriginal('template_version_id') === null) {
                return;
            }

            $contentDirty = $send->isDirty('template_family_id')
                || $send->isDirty('channel')
                || $send->isDirty('subject_override');
            $versionDirty = $send->isDirty('template_version_id');

            if ($contentDirty || ($versionDirty && ! self::versionMoveAllowed($send))) {
                throw CampaignImmutable::pinnedSend();
            }
        });
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<TemplateFamily, $this> */
    public function templateFamily(): BelongsTo
    {
        return $this->belongsTo(TemplateFamily::class);
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    private static function versionMoveAllowed(self $send): bool
    {
        $campaign = Campaign::query()->find($send->campaign_id);
        $status = $campaign?->status;
        $statusValue = $status instanceof CampaignStatus ? $status->value : (string) $status;

        if ($statusValue !== CampaignStatus::PendingApproval->value) {
            return false;
        }

        $newId = $send->template_version_id;
        if ($newId === null) {
            return true;
        }

        $version = TemplateVersion::query()->find($newId);

        return $version !== null
            && (int) $version->template_family_id === (int) $send->getOriginal('template_family_id')
            && $version->status === TemplateVersionStatus::Published;
    }
}
