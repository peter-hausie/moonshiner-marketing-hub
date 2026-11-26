<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CurrentPublicationApproval
{
    /** Point-in-time validation only; execution must retain compatible locks and recheck media. */
    public function requireCurrent(int $publicationId): int
    {
        return DB::transaction(function () use ($publicationId): int {
            $publication = DB::table('publications')->where('id', $publicationId)->lockForUpdate()->firstOrFail();
            $review = DB::table('publication_reviews')->where('publication_id', $publicationId)
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($review === null || $review->decision !== 'approved') {
                throw new RuntimeException('No current approval exists.');
            }
            if ($review->content_version_id !== $publication->content_version_id || $review->channel !== $publication->channel) {
                throw new RuntimeException('The approved publication binding has changed.');
            }
            DB::table('content_versions')->where('id', $publication->content_version_id)->lockForUpdate()->firstOrFail();
            $fingerprint = app(PublicationReviewFingerprint::class)->forVerifiedPublication($publicationId);
            if (! hash_equals($review->fingerprint, $fingerprint)) {
                throw new RuntimeException('The approved snapshot has changed.');
            }

            return $review->id;
        }, attempts: 3);
    }
}
