<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AiSummary;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasAiSummary
{
    /** @return MorphMany<AiSummary, $this> */
    public function aiSummaries(): MorphMany
    {
        return $this->morphMany(AiSummary::class, 'summarizable');
    }

    /** @return MorphOne<AiSummary, $this> */
    public function currentAiSummary(): MorphOne
    {
        return $this->morphOne(AiSummary::class, 'summarizable')->current();
    }
}
