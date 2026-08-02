<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uploaded image (or other binary) for email templates. Stored on the
 * template-assets disk; publicly served via content-hash URL.
 *
 * @property int         $id
 * @property string      $hash
 * @property string      $disk_path
 * @property string      $original_filename
 * @property string      $mime_type
 * @property int         $size_bytes
 * @property int|null    $created_by
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Employee|null $creator
 */
class TemplateAsset extends Model
{
    protected $fillable = [
        'hash',
        'disk_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function publicUrl(): string
    {
        $filename = rawurlencode($this->original_filename);
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/api/public/template-assets/'.$this->hash.'/'.$filename;
    }

    public function isReferenced(): bool
    {
        $hash = $this->hash;
        $id = $this->id;

        return TemplateVariant::query()
            ->whereNotNull('blocks')
            ->where(function ($q) use ($hash, $id): void {
                $q->where('blocks', 'like', '%"asset_id":'.$id.'%')
                    ->orWhere('blocks', 'like', '%"asset_id": '.$id.'%')
                    ->orWhere('blocks', 'like', '%'.$hash.'%');
            })
            ->exists();
    }
}
