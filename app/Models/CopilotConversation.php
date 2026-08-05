<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Ai\Models\Conversation;

/**
 * App overlay on the SDK conversation row — soft archive + audit snapshot.
 * Participant morph and message persistence stay with laravel/ai.
 */
class CopilotConversation extends Conversation
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'site_scope_snapshot' => 'array',
            'deleted_at' => 'datetime',
        ];
    }
}
