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
        static::creating(function (self $variant): void {
            if (self::versionIsPublished($variant->template_version_id)) {
                throw PublishedTemplateImmutable::variant();
            }
        });

        static::updating(function (self $variant): void {
            $originalVersionId = $variant->getOriginal('template_version_id');
            $originalFamilyId = $variant->getOriginal('template_family_id');

            if ((int) $originalVersionId !== (int) $variant->template_version_id
                || (int) $originalFamilyId !== (int) $variant->template_family_id) {
                throw PublishedTemplateImmutable::variant();
            }

            if (self::versionIsPublished($originalVersionId) || self::versionIsPublished($variant->template_version_id)) {
                throw PublishedTemplateImmutable::variant();
            }
        });

        static::deleting(function (self $variant): void {
            $versionId = $variant->getOriginal('template_version_id') ?? $variant->template_version_id;
            if (self::versionIsPublished($versionId)) {
                throw PublishedTemplateImmutable::variant();
            }
        });
    }

    private static function versionIsPublished(mixed $versionId): bool
    {
        if ($versionId === null || $versionId === '') {
            return false;
        }

        $status = TemplateVersion::query()->whereKey($versionId)->value('status');
        $raw = $status instanceof TemplateVersionStatus ? $status->value : $status;

        return $raw === TemplateVersionStatus::Published->value;
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
