<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $email_template_id
 * @property string $type
 * @property array  $props
 * @property int    $order
 */
class EmailBlock extends TenantModel
{
    protected $fillable = [
        'email_template_id',
        'type',
        'props',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'props' => 'array',
        ];
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }
}
