<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['publication_id', 'content_item_id', 'content_version_id', 'attempt_number', 'provenance', 'started_at', 'payload_sha256'])]
class PublicationAttempt extends Model
{
    protected $attributes = ['status' => 'started'];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
