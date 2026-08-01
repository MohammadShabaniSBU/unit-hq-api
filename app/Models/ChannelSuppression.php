<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\Channel;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Address-keyed suppression. Follows the channel value, not the contact.
 *
 * @property int         $id
 * @property string      $channel
 * @property string      $address
 * @property string      $scope
 * @property string      $reason
 * @property int|null    $source_message_id
 * @property int|null    $created_by
 * @property Carbon|null $lifted_at
 * @property int|null    $lifted_by
 * @property string|null $lift_reason
 * @property Carbon      $created_at
 *
 * @property-read Message|null   $sourceMessage
 * @property-read Employee|null  $creator
 */
class ChannelSuppression extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'channel',
        'address',
        'scope',
        'reason',
        'source_message_id',
        'created_by',
        'lifted_at',
        'lifted_by',
        'lift_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'scope' => SuppressionScope::class,
            'reason' => SuppressionReason::class,
            'lifted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Message, ChannelSuppression> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return BelongsTo<Employee, ChannelSuppression> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @param  Builder<ChannelSuppression>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }
}
