<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'body', 'image_path', 'context', 'content_type', 'status', 'channels', 'scheduled_at', 'submitted_by', 'reviewed_by', 'review_notes', 'responsible_user_id'])]
class ContentItem extends Model
{
    use HasFactory;

    protected $attributes = [
        'content_type' => ContentType::Editorial->value,
        'status' => ContentStatus::Draft->value,
    ];

    protected static function booted(): void
    {
        static::saved(function (ContentItem $item): void {
            $isPublishable = in_array($item->status, [ContentStatus::Approved, ContentStatus::Scheduled], true);
            $channels = collect($item->channels ?? [])->map(fn (string $channel) => strtolower($channel))->unique();

            if (! $isPublishable) {
                $item->publications()->where('status', PublicationStatus::Pending)->delete();

                return;
            }

            $channels->each(fn (string $channel) => $item->publications()->updateOrCreate(
                ['channel' => $channel],
                ['scheduled_at' => $item->scheduled_at],
            ));

            $item->publications()
                ->where('status', PublicationStatus::Pending)
                ->whereNotIn('channel', $channels->all())
                ->delete();
        });
    }

    protected function performDeleteOnModel()
    {
        // Remove only the association. Preserve research and roll back the detach
        // if a retained version or another constraint prevents deletion.
        $this->getConnection()->transaction(function (): void {
            $this->opportunities()->detach();
            parent::performDeleteOnModel();
        });
    }

    public function opportunities(): BelongsToMany
    {
        return $this->belongsToMany(Opportunity::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    protected function casts(): array
    {
        return [
            'content_type' => ContentType::class,
            'status' => ContentStatus::class,
            'channels' => 'array',
            'scheduled_at' => 'datetime',
        ];
    }
}
