<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateAssistTurnStatus;
use App\Support\Communications\Exceptions\TemplateAssistTurnFrozen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One assistant turn against a draft. Document columns freeze once status leaves running.
 *
 * @property int $id
 * @property int $session_id
 * @property string $call_id
 * @property string $client_message_id
 * @property int $template_version_id
 * @property array<string, mixed>|null $selection
 * @property array<string, mixed> $base_document
 * @property string $base_hash
 * @property array<string, mixed> $working_document
 * @property list<array<string, mixed>> $ops
 * @property array<string, mixed>|null $subject_change
 * @property array<string, mixed> $op_summary
 * @property TemplateAssistTurnStatus $status
 * @property string|null $error_key
 * @property string $prompt_version
 * @property int $tool_call_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TemplateAssistSession $session
 * @property-read TemplateVersion $templateVersion
 */
class TemplateAssistTurn extends Model
{
    protected $fillable = [
        'session_id',
        'call_id',
        'client_message_id',
        'template_version_id',
        'selection',
        'base_document',
        'base_hash',
        'working_document',
        'ops',
        'subject_change',
        'op_summary',
        'status',
        'error_key',
        'prompt_version',
        'tool_call_count',
    ];

    /**
     * @var list<string>
     */
    private const FROZEN_COLUMNS = [
        'base_document',
        'working_document',
        'ops',
        'subject_change',
    ];

    protected function casts(): array
    {
        return [
            'selection' => 'array',
            'base_document' => 'array',
            'working_document' => 'array',
            'ops' => 'array',
            'subject_change' => 'array',
            'op_summary' => 'array',
            'status' => TemplateAssistTurnStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $turn): void {
            if ($turn->status === null) {
                $turn->status = TemplateAssistTurnStatus::Running;
            }
        });

        static::updating(function (self $turn): void {
            $original = self::statusValue($turn->getOriginal('status'));

            if ($original !== TemplateAssistTurnStatus::Running->value) {
                foreach (self::FROZEN_COLUMNS as $column) {
                    if ($turn->isDirty($column)) {
                        throw TemplateAssistTurnFrozen::column($column);
                    }
                }
            }

            if (! $turn->isDirty('status')) {
                return;
            }

            $from = TemplateAssistTurnStatus::from($original);
            $next = $turn->status instanceof TemplateAssistTurnStatus
                ? $turn->status
                : TemplateAssistTurnStatus::from((string) $turn->status);

            if (! $from->canTransitionTo($next)) {
                throw TemplateAssistTurnFrozen::transition($from->value, $next->value);
            }
        });
    }

    /** @return BelongsTo<TemplateAssistSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TemplateAssistSession::class, 'session_id');
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    private static function statusValue(mixed $status): string
    {
        return $status instanceof TemplateAssistTurnStatus ? $status->value : (string) $status;
    }
}
