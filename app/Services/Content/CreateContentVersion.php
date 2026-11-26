<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateContentVersion
{
    /** Append a persisted snapshot; never approve, bind or publish it. */
    public function create(int $contentItemId, int $authorId, string $provenance, bool $snapshotMedia = false): int
    {
        // The baseline namespace belongs exclusively to the migration/rollback.
        if (! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $provenance) || $provenance === 'legacy_baseline') {
            throw new InvalidArgumentException('Explicit non-baseline version provenance is required.');
        }

        return DB::transaction(function () use ($contentItemId, $authorId, $provenance, $snapshotMedia): int {
            // Serialize cooperating writers even when this piece has no versions yet.
            // Read persisted fields under the lock, not a caller's stale model.
            $item = DB::table('content_items')->where('id', $contentItemId)->lockForUpdate()->firstOrFail();
            $latest = DB::table('content_versions')->where('content_item_id', $contentItemId)
                ->orderByDesc('version_number')->lockForUpdate()->first();

            // Files are append-only, not transactional. Retain unreferenced objects
            // after rollback: another version may already share the same digest.
            $media = $snapshotMedia && $item->image_path !== null
                ? app(SnapshotContentMedia::class)->create($item->image_path)
                : null;

            return DB::table('content_versions')->insertGetId([
                'content_item_id' => $item->id,
                'version_number' => ($latest?->version_number ?? 0) + 1,
                'title' => $item->title,
                'body' => $item->body,
                'context' => $item->context,
                'content_type' => $item->content_type,
                // A legacy path reference, NOT an immutable media-byte snapshot.
                'image_path' => $item->image_path,
                'media_snapshot' => $media === null ? null : json_encode($media, JSON_THROW_ON_ERROR),
                'created_by' => $authorId,
                'provenance' => $provenance,
                'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}
