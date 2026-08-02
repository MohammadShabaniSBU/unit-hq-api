<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Template identity (channel, name, purpose) with per-locale variants for content.
 *
 * @property int                  $id
 * @property TemplateChannel      $channel
 * @property string               $name
 * @property TemplatePurpose      $purpose
 * @property Carbon|null          $archived_at
 * @property Carbon               $created_at
 * @property Carbon               $updated_at
 *
 * @property-read Collection<int, TemplateVariant> $variants
 */
class TemplateFamily extends Model
{
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

    /** @return HasMany<TemplateVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(TemplateVariant::class)->orderBy('locale');
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
