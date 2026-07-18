<?php

namespace App\Models;

use App\Enums\LogChannel;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $coverage    NUMERIC(10,2)
 * @property string      $currency    CHAR(3) e.g. EUR
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, InsuranceRate>  $rates
 * @property-read Collection<int, ContractItem>  $contractItems
 */
class Insurance extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Facility;
    }

    protected $fillable = [
        'name',
        'description',
        'coverage',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'coverage' => 'decimal:2',
        ];
    }

    /** @return HasMany<InsuranceRate, Insurance> */
    public function rates(): HasMany
    {
        return $this->hasMany(InsuranceRate::class);
    }

    /** @return MorphMany<ContractItem, Insurance> */
    public function contractItems(): MorphMany
    {
        return $this->morphMany(ContractItem::class, 'item');
    }
}
