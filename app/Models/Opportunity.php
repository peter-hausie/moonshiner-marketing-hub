<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'rationale', 'provenance', 'created_by'])]
class Opportunity extends Model
{
    protected $attributes = ['status' => 'proposed'];

    public function researchItems(): BelongsToMany
    {
        return $this->belongsToMany(ResearchItem::class);
    }

    public function contentItems(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class);
    }
}
