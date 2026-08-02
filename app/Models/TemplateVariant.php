<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-locale content for a template family.
 *
 * @property int                           $id
 * @property int                           $template_family_id
 * @property string                        $locale
 * @property string|null                   $subject
 * @property array<string, mixed>|null     $blocks
 * @property string|null                   $legacy_html
 * @property string|null                   $body_text
 * @property int|null                      $updated_by
 * @property Carbon                        $created_at
 * @property Carbon                        $updated_at
 *
 * @property-read TemplateFamily           $family
 * @property-read Employee|null            $updater
 */
class TemplateVariant extends Model
{
    protected $fillable = [
        'template_family_id',
        'locale',
        'subject',
        'blocks',
        'legacy_html',
        'body_text',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
        ];
    }

    /** @return BelongsTo<TemplateFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(TemplateFamily::class, 'template_family_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
}
