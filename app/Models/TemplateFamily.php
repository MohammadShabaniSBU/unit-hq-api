<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Template identity (channel, name, purpose). Content lives on versions.
 *
 * @property int $id
 * @property TemplateChannel $channel
 * @property string $name
 * @property TemplatePurpose $purpose
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, TemplateVersion> $versions
 * @property-read TemplateVersion|null $currentVersion
 * @property-read TemplateVersion|null $draft
 */
class TemplateFamily extends Model
{
    /** @use HasFactory<TemplateFamilyFactory> */
    use HasFactory;

    protected $fillable = [
        'channel',
        'name',
        'purpose',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => TemplateChannel::class,
            'purpose' => TemplatePurpose::class,
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<TemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class)->orderBy('version_number');
    }

    /**
     * Latest published version. Derived, not a stored pointer.
     *
     * @return HasOne<TemplateVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(TemplateVersion::class)->ofMany(
            ['version_number' => 'max'],
            fn (Builder $query) => $query->where('status', TemplateVersionStatus::Published->value),
        );
    }

    /** @return HasOne<TemplateVersion, $this> */
    public function draft(): HasOne
    {
        return $this->hasOne(TemplateVersion::class)
            ->where('status', TemplateVersionStatus::Draft->value);
    }

    /** @param  Builder<TemplateFamily>  $query */
    public function scopeNotArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<TemplateFamily>  $query */
    public function scopeChannel(Builder $query, TemplateChannel|string $channel): void
    {
        $query->where('channel', $channel instanceof TemplateChannel ? $channel->value : $channel);
    }

    /**
     * @param  Builder<TemplateFamily>  $query
     * @param  list<string>|TemplatePurpose|string  $purposes
     */
    public function scopePurposeIn(Builder $query, array|TemplatePurpose|string $purposes): void
    {
        if ($purposes instanceof TemplatePurpose) {
            $purposes = $purposes->pickerAllowlist();
        } elseif (is_string($purposes)) {
            $purposes = TemplatePurpose::from($purposes)->pickerAllowlist();
        }

        $query->whereIn('purpose', $purposes);
    }
}
