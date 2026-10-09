<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\Channel;
use Database\Factories\SubscriptionListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marketing subscription list. Membership is derived from consent_events.
 *
 * @property int $id
 * @property string $key
 * @property array<string, string> $labels
 * @property array<string, string>|null $descriptions
 * @property string $kind
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

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
