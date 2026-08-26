<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-channel, per-site (or company-wide) agent autonomy. Absent row = off.
 *
 * @property int $id
 * @property int $ai_agent_id
 * @property AgentChannel $channel
 * @property int|null $site_id
 * @property BindingMode $mode
 * @property BindingAudience $audience
 * @property OutsideHoursPolicy $outside_hours
 * @property int|null $updated_by_employee_id
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AiAgent $agent
 * @property-read Site|null $site
 * @property-read Employee|null $updatedBy
 */
class AgentChannelBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_agent_id',
        'channel',
        'site_id',
        'mode',
        'audience',
        'outside_hours',
        'updated_by_employee_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => AgentChannel::class,
            'mode' => BindingMode::class,
            'audience' => BindingAudience::class,
            'outside_hours' => OutsideHoursPolicy::class,
            'archived_at' => 'datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by_employee_id');
    }
}
