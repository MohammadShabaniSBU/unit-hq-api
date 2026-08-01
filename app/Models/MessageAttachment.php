<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * File attached to a message; stored on the private disk.
 *
 * @property int         $id
 * @property int         $message_id
 * @property string      $filename
 * @property string      $mime_type
 * @property int         $size_bytes
 * @property string      $disk_path
 * @property Carbon|null $created_at
 *
 * @property-read Message $message
 */
class MessageAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'filename',
        'mime_type',
        'size_bytes',
        'disk_path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Message, MessageAttachment> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
