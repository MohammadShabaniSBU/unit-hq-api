<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\LogChannel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

trait LogsDirtyActivity
{
    use LogsActivity;

    abstract protected function activityLogChannel(): LogChannel;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->activityLogChannel()->value)
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
