<?php

namespace App\Models;

use App\Enums\AutomationNodeKind;
use App\Enums\AutomationNodeType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single node within an automation graph (trigger or action).
 *
 * The `config` JSON column stores the typed configuration for this node type.
 * Its structure mirrors the TypeScript config interfaces in automation.ts.
 *
 * @property int                  $id
 * @property int                  $automation_id
 * @property string               $node_key
 * @property AutomationNodeKind   $kind
 * @property AutomationNodeType   $type
 * @property string               $label
 * @property string|null          $description
 * @property int                  $position_x
 * @property int                  $position_y
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $metadata
 * @property Carbon               $created_at
 * @property Carbon               $updated_at
 *
 * @property-read Automation                         $automation
 * @property-read Collection<int, AutomationRunStep> $runSteps
 */
class AutomationNode extends TenantModel
{
    protected $fillable = [
        'automation_id',
        'node_key',
        'kind',
        'type',
        'label',
        'description',
        'position_x',
        'position_y',
        'config',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'kind'       => AutomationNodeKind::class,
            'type'       => AutomationNodeType::class,
            'position_x' => 'integer',
            'position_y' => 'integer',
            'config'     => 'array',
            'metadata'   => 'array',
        ];
    }

    /** @return BelongsTo<Automation, AutomationNode> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return HasMany<AutomationRunStep> */
    public function runSteps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class, 'node_id');
    }
}
