<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateVersionStatus;
use App\Support\Communications\Exceptions\PublishedTemplateImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-locale content for one template version.
 *
 * @property int $id
 * @property int $template_family_id
 * @property int $template_version_id
 * @property string $locale
 * @property string|null $subject
 * @property array<string, mixed>|null $blocks
 * @property string|null $legacy_html
 * @property string|null $body_text
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TemplateFamily           $family
 * @property-read TemplateVersion          $version
 * @property-read Employee|null            $updater
 */
class TemplateVariant extends Model
{
    protected $fillable = [
        'template_family_id',
        'template_version_id',
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

    protected static function booted(): void
    {
        $guard = function (self $variant): void {
            $status = $variant->version()->value('status');
            $raw = $status instanceof TemplateVersionStatus ? $status->value : $status;
            if ($raw === TemplateVersionStatus::Published->value) {
                throw PublishedTemplateImmutable::variant();
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<TemplateFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(TemplateFamily::class, 'template_family_id');
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
}
