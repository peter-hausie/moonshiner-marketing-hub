<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class PublicationReviewFingerprint
{
    /**
     * Read-only review identity, NOT authorization or a media integrity check.
     * Approval/dispatch callers must lock, authorize and verify bytes separately.
     */
    public function forPublication(int $publicationId): string
    {
        return $this->fingerprint($publicationId, false);
    }

    /** Fresh byte verification for future approval/dispatch callers; not approval. */
    public function forVerifiedPublication(int $publicationId): string
    {
        return $this->fingerprint($publicationId, true);
    }

    private function fingerprint(int $publicationId, bool $verifyMedia): string
    {
        // One statement avoids mixing a binding and version from separate reads.
        $row = DB::table('publications as p')
            ->join('content_versions as v', function ($join): void {
                $join->on('v.id', '=', 'p.content_version_id')
                    ->on('v.content_item_id', '=', 'p.content_item_id');
            })
            ->where('p.id', $publicationId)
            ->select('p.id as publication_id', 'p.channel', 'v.*')
            ->first();

        if ($row === null || trim($row->channel) === '') {
            throw new RuntimeException('Review requires a channel and a bound content version.');
        }

        $media = null;
        if ($row->media_snapshot !== null) {
            try {
                $media = json_decode($row->media_snapshot, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Review requires canonical snapshot metadata.', previous: $exception);
            }
            // Only SQL NULL denotes absent media; JSON null is invalid metadata.
            if (! is_array($media)) {
                throw new RuntimeException('Review requires canonical snapshot metadata.');
            }
        }
        if ($media === null && $row->image_path !== null) {
            throw new RuntimeException('Legacy image references cannot identify reviewable media.');
        }
        if ($media !== null) {
            if (! is_array($media)
                || ($media['disk'] ?? null) !== 'content_snapshots'
                || ! is_string($media['sha256'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $media['sha256'])
                || ($media['path'] ?? null) !== $media['sha256']
                || ! is_int($media['bytes'] ?? null)
                || $media['bytes'] < 1 || $media['bytes'] > 25 * 1024 * 1024
                || count($media) !== 4) {
                throw new RuntimeException('Review requires canonical snapshot metadata.');
            }
            if ($verifyMedia) {
                app(VerifyContentMedia::class)->verify($media);
            }
            // JSON object key order must not change review identity.
            $media = ['disk' => $media['disk'], 'path' => $media['path'], 'sha256' => $media['sha256'], 'bytes' => $media['bytes']];
        }

        return hash('sha256', json_encode([
            'format' => 'publication-review-v1',
            'publication_id' => (int) $row->publication_id,
            'content_item_id' => (int) $row->content_item_id,
            'content_version_id' => (int) $row->id,
            'channel' => $row->channel,
            'title' => $row->title,
            'body' => $row->body,
            'context' => $row->context,
            'content_type' => $row->content_type,
            'media' => $media,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
