<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['source_url', 'title', 'excerpt', 'observed_at', 'provenance', 'coverage', 'created_by'])]
class ResearchItem extends Model
{
    public function opportunities(): BelongsToMany
    {
        return $this->belongsToMany(Opportunity::class);
    }

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'event_at' => 'immutable_datetime',
            'coverage' => 'array',
        ];
    }
}
