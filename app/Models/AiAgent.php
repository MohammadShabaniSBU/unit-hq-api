<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Agents\AgentRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Instance row for a customer-facing agent (D-AI-4 / D-AI-6). Archive-only.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $model
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, AgentConversation> $conversations
 * @property-read Collection<int, AgentWritePolicy> $writePolicies
 */
class AiAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'model',
        'is_active',
        'settings',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('archived_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function definition(): AgentDefinition
    {
        return app(AgentRegistry::class)->get($this->key);
    }

    /** @return HasMany<AgentConversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class);
    }

    /** @return HasMany<AgentWritePolicy, $this> */
    public function writePolicies(): HasMany
    {
        return $this->hasMany(AgentWritePolicy::class);
    }
}
