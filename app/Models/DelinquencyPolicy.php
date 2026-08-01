<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Named escalation ladder assigned per site. Archive-only.
 *
 * Operational (not contractual) — edits affect future evaluation only;
 * already-executed case steps remain history (S07 invariant exception).
 *
 * @property int         $id
 * @property string      $name
 * @property bool        $auto_release_overlock
 * @property Carbon|null $archived_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, DelinquencyPolicyStep> $steps
 * @property-read Collection<int, Site>                  $sites
 * @property-read Collection<int, Delinquency>           $delinquencies
 * @property-read int|null                               $sites_count
 */
class DelinquencyPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'auto_release_overlock',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'auto_release_overlock' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<DelinquencyPolicy> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<DelinquencyPolicy> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return HasMany<DelinquencyPolicyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(DelinquencyPolicyStep::class)->orderBy('sort');
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<Delinquency, $this> */
    public function delinquencies(): HasMany
    {
        return $this->hasMany(Delinquency::class);
    }
}
