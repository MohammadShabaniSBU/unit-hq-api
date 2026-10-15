<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Links one SDK conversation to a template family, locale, and employee.
 *
 * @property int $id
 * @property int $template_family_id
 * @property string $locale
 * @property string $conversation_id
 * @property int $employee_id
 * @property Carbon|null $last_turn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TemplateFamily $family
 * @property-read CopilotConversation $conversation
 * @property-read Employee $employee
 * @property-read Collection<int, TemplateAssistTurn> $turns
 */
class TemplateAssistSession extends Model
{
    protected $fillable = [
        'template_family_id',
        'locale',
        'conversation_id',
        'employee_id',
        'last_turn_at',
    ];

    protected function casts(): array
    {
        return [
            'last_turn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TemplateFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(TemplateFamily::class, 'template_family_id');
    }

    /** @return BelongsTo<CopilotConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CopilotConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<TemplateAssistTurn, $this> */
    public function turns(): HasMany
    {
        return $this->hasMany(TemplateAssistTurn::class, 'session_id');
    }
}
