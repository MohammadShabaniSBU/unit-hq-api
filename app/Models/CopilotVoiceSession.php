<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-install voice-minute telemetry for the employee copilot. Not a ledger.
 *
 * @property int $id
 * @property int $employee_id
 * @property string|null $conversation_id
 * @property string|null $vb_session_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property int $turn_count
 * @property string|null $end_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CopilotVoiceSession extends Model
{
    protected $fillable = [
        'employee_id',
        'conversation_id',
        'vb_session_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'turn_count',
        'end_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'turn_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
