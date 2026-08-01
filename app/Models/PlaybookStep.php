<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlaybookStepAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ordered step in a linear playbook.
 *
 * @property int                     $id
 * @property int                     $playbook_id
 * @property int                     $offset_days
 * @property PlaybookStepAction      $action
 * @property array<string, mixed>    $params
 * @property int                     $sort
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 *
 * @property-read Playbook $playbook
 */
class PlaybookStep extends Model
{
    protected $fillable = [
        'playbook_id',
        'offset_days',
        'action',
        'params',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'action' => PlaybookStepAction::class,
            'params' => 'array',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Playbook, PlaybookStep> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }
}
