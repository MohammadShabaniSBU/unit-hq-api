<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Top-level automation definition — a named, versioned flow graph.
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property bool        $enabled
 * @property int         $version
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, AutomationNode> $nodes
 * @property-read Collection<int, AutomationEdge> $edges
 * @property-read Collection<int, AutomationRun>  $runs
 */
class Automation extends TenantModel
{
    protected $fillable = [
        'name',
        'description',
        'enabled',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
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
}
