<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateVersionStatus;
use App\Support\Communications\Exceptions\PublishedTemplateImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One draft or published snapshot of a template family.
 *
 * @property int $id
 * @property int $template_family_id
 * @property int $version_number
 * @property TemplateVersionStatus $status
 * @property int|null $based_on_version_id
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TemplateFamily       $family
 * @property-read TemplateVersion|null $basedOn
 * @property-read Collection<int, TemplateVariant> $variants
 * @property-read Employee|null        $publisher
 * @property-read Employee|null        $creator
 */
class TemplateVersion extends Model
{
    protected $fillable = [
        'template_family_id',
        'version_number',
        'status',
        'based_on_version_id',
        'published_at',
        'published_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TemplateVersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if (self::statusValue($version->getOriginal('status')) === TemplateVersionStatus::Published->value) {
                throw PublishedTemplateImmutable::version();
            }
        });

        static::deleting(function (self $version): void {
            $original = self::statusValue($version->getOriginal('status'));
            $current = $version->status === TemplateVersionStatus::Published
                ? TemplateVersionStatus::Published->value
                : self::statusValue($version->status);

            if ($original === TemplateVersionStatus::Published->value || $current === TemplateVersionStatus::Published->value) {
                throw PublishedTemplateImmutable::version();
            }
        });
    }

    /** @return BelongsTo<TemplateFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(TemplateFamily::class, 'template_family_id');
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_version_id');
    }

    /** @return HasMany<TemplateVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(TemplateVariant::class)->orderBy('locale');
    }

    /** @return BelongsTo<Employee, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'published_by');
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    private static function statusValue(mixed $status): string
    {
        return $status instanceof TemplateVersionStatus ? $status->value : (string) $status;
    }
}
