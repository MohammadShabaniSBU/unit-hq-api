<?php

namespace App\Models\Concerns;

use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasNotes
{
    /** @return MorphMany<Note> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }
}
