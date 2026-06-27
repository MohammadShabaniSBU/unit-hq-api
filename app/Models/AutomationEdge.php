<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A directed connection between two nodes in the automation graph.
 *
 * The `condition` JSON stores an EdgeCondition:
 *   { type: 'always' } — edge is always traversed (default)
 *   { type: 'filter', filterGroup: FilterGroup } — traversed only when the filter passes
 *
 * @property int                  $id
 * @property int                  $automation_id
 * @property int                  $source_node_id
 * @property int                  $target_node_id
 * @property string|null          $source_handle
 * @property string|null          $target_handle
 * @property string|null          $label
 * @property array<string, mixed> $condition
 * @property Carbon               $created_at
 * @property Carbon               $updated_at
 *
 * @property-read Automation     $automation
 * @property-read AutomationNode $sourceNode
 * @property-read AutomationNode $targetNode
 */
class AutomationEdge extends TenantModel
{
    protected $fillable = [
        'automation_id',
        'source_node_id',
        'target_node_id',
        'source_handle',
        'target_handle',
        'label',
        'condition',
    ];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
        ];
    }

    /** @return BelongsTo<Automation, AutomationEdge> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return BelongsTo<AutomationNode, AutomationEdge> */
    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'source_node_id');
    }

    /** @return BelongsTo<AutomationNode, AutomationEdge> */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'target_node_id');
    }
}
