<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Top-level automation definition — a named, versioned flow graph.
 *
 * @property int                            $id
 * @property string                         $name
 * @property string|null                    $description
 * @property AutomationStatus               $status
 * @property int                            $version
 * @property bool                           $single_active_run_per_subject
 * @property array<string, mixed>|null      $default_guard
 * @property int|null                       $playbook_id
 * @property Carbon|null                    $archived_at
 * @property Carbon                         $created_at
 * @property Carbon                         $updated_at
 *
 * @property-read Collection<int, AutomationNode> $nodes
 * @property-read Collection<int, AutomationEdge> $edges
 * @property-read Collection<int, AutomationRun>  $runs
 * @property-read Playbook|null                   $playbook
 */
class Automation extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
        'version',
        'single_active_run_per_subject',
        'default_guard',
        'playbook_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationStatus::class,
            'version' => 'integer',
            'single_active_run_per_subject' => 'boolean',
            'default_guard' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<Automation>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<Automation>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return HasMany<AutomationNode> */
    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationNode::class);
    }

    /** @return HasMany<AutomationEdge> */
    public function edges(): HasMany
    {
        return $this->hasMany(AutomationEdge::class);
    }

    /** @return HasMany<AutomationRun> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /** @return BelongsTo<Playbook, Automation> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }
}
