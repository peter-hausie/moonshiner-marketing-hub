<?php

namespace App\Models;

use App\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['content_item_id', 'channel', 'status', 'scheduled_at', 'published_at', 'external_id', 'external_url', 'attempts', 'last_error'])]
class Publication extends Model
{
    protected $attributes = [
        'status' => PublicationStatus::Pending->value,
        'attempts' => 0,
    ];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function executionAttempts(): HasMany
    {
        return $this->hasMany(PublicationAttempt::class);
    }

    public function latestExecutionAttempt(): HasOne
    {
        return $this->hasOne(PublicationAttempt::class)->ofMany('attempt_number', 'max');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(PublicationReview::class)->latestOfMany();
    }

    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
