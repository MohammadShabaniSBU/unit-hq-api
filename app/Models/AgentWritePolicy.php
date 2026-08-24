<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Tools\AgentTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-agent, per-tool write autonomy. Absent row = commit, unlimited.
 *
 * @property int $id
 * @property int $ai_agent_id
 * @property string $tool_key
 * @property WritePolicyMode $mode
 * @property int|null $max_per_conversation
 * @property int|null $max_per_day
 * @property VerificationLevel|null $min_verification
 * @property int|null $updated_by_employee_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AiAgent $agent
 * @property-read Employee|null $updatedBy
 */
class AgentWritePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_agent_id',
        'tool_key',
        'mode',
        'max_per_conversation',
        'max_per_day',
        'min_verification',
        'updated_by_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'mode' => WritePolicyMode::class,
            'min_verification' => VerificationLevel::class,
            'max_per_conversation' => 'integer',
            'max_per_day' => 'integer',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeForTool(Builder $query, string $toolKey): void
    {
        $query->where('tool_key', $toolKey);
    }

    public function allows(): bool
    {
        return $this->mode !== WritePolicyMode::Off;
    }

    public function effectiveVerification(AgentTool $tool): VerificationLevel
    {
        $floor = $tool->requiredVerification();
        if ($this->min_verification === null) {
            return $floor;
        }

        return $this->min_verification->rank() >= $floor->rank()
            ? $this->min_verification
            : $floor;
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by_employee_id');
    }
}
