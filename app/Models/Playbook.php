<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlaybookKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Linear playbook definition that compiles to an automation graph.
 *
 * @property int                            $id
 * @property PlaybookKind                   $kind
 * @property string                         $name
 * @property bool                           $is_active
 * @property array<string, mixed>           $enrolment_filters
 * @property int|null                       $automation_id
 * @property Carbon|null                    $archived_at
 * @property Carbon                         $created_at
 * @property Carbon                         $updated_at
 *
 * @property-read Automation|null                      $automation
 * @property-read Collection<int, PlaybookStep>        $steps
 * @property-read Collection<int, Automation>          $automations
 */
class Playbook extends Model
{
    protected $fillable = [
        'kind',
        'name',
        'is_active',
        'enrolment_filters',
        'automation_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PlaybookKind::class,
            'is_active' => 'boolean',
            'enrolment_filters' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<Playbook>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<Playbook>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return BelongsTo<Automation, Playbook> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return HasMany<PlaybookStep> */
    public function steps(): HasMany
    {
        return $this->hasMany(PlaybookStep::class)->orderBy('sort');
    }

    /** @return HasMany<Automation> */
    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }
}
