<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\Channel;
use App\Support\Marketing\SubscriptionListKind;
use Database\Factories\SubscriptionListFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Marketing subscription list. Membership is derived from consent_events.
 *
 * @property int $id
 * @property string $key
 * @property array<string, string> $labels
 * @property array<string, string>|null $descriptions
 * @property SubscriptionListKind $kind
 * @property list<string> $channels
 * @property int|null $site_id
 * @property bool $is_public
 * @property bool $requires_double_opt_in
 * @property bool $is_system
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SubscriptionList extends Model
{
    /** @use HasFactory<SubscriptionListFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'labels',
        'descriptions',
        'kind',
        'channels',
        'site_id',
        'is_public',
        'requires_double_opt_in',
        'is_system',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'descriptions' => 'array',
            'kind' => SubscriptionListKind::class,
            'channels' => 'array',
            'is_public' => 'boolean',
            'requires_double_opt_in' => 'boolean',
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function allows(Channel $channel): bool
    {
        return in_array($channel->value, $this->channels, true);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public static function memberCountCacheKey(int $listId): string
    {
        return 'subscription-list:'.$listId.':member-counts';
    }

    public function forgetMemberCounts(): void
    {
        Cache::forget(self::memberCountCacheKey($this->id));
    }

    /**
     * Channels this list accepts for marketing consent.
     *
     * @return list<Channel>
     */
    public function marketingChannels(): array
    {
        $channels = [];

        foreach ($this->channels as $value) {
            $channel = Channel::tryFrom($value);
            if ($channel === null) {
                continue;
            }
            if (! in_array($channel, [Channel::Email, Channel::Sms, Channel::Whatsapp], true)) {
                continue;
            }
            $channels[] = $channel;
        }

        return $channels;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
