<?php

namespace App\Services\Content;

use App\Enums\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecordPublicationReview
{
    /** Internal only: callers must supply the authenticated human's ID, never input identity. */
    public function approve(int $publicationId, int $curatorId, string $reviewedFingerprint): int
    {
        return $this->record($publicationId, $curatorId, $reviewedFingerprint);
    }

    /** Revocation must remain possible even when old media is missing or binding changed. */
    public function revoke(int $publicationId, int $curatorId): int
    {
        return $this->record($publicationId, $curatorId, null);
    }

    private function record(int $publicationId, int $curatorId, ?string $reviewedFingerprint): int
    {
        return DB::transaction(function () use ($publicationId, $curatorId, $reviewedFingerprint): int {
            $actor = DB::table('users')->where('id', $curatorId)->lockForUpdate()->first();
            if ($actor === null || $actor->role !== UserRole::Curator->value) {
                throw new AuthorizationException('A current human curator is required.');
            }
            $publication = DB::table('publications')->where('id', $publicationId)->lockForUpdate()->firstOrFail();
            $latest = DB::table('publication_reviews')->where('publication_id', $publicationId)
                ->orderByDesc('id')->lockForUpdate()->first();

            if ($reviewedFingerprint === null) {
                if ($latest === null) {
                    throw new RuntimeException('No approval exists to revoke.');
                }
                if ($latest->decision === 'revoked') {
                    return $latest->id;
                }
                $versionId = $latest->content_version_id;
                $channel = $latest->channel;
                $fingerprint = $latest->fingerprint;
            } else {
                DB::table('content_versions')->where('id', $publication->content_version_id)->lockForUpdate()->firstOrFail();
                $fingerprint = app(PublicationReviewFingerprint::class)->forVerifiedPublication($publicationId);
                if (! hash_equals($fingerprint, $reviewedFingerprint)) {
                    throw new RuntimeException('The reviewed snapshot has changed; review it again.');
                }
                if ($latest !== null && $latest->decision === 'approved' && hash_equals($latest->fingerprint, $fingerprint)) {
                    return $latest->id;
                }
                $versionId = $publication->content_version_id;
                $channel = $publication->channel;
            }

            return DB::table('publication_reviews')->insertGetId([
                'publication_id' => $publicationId,
                'content_version_id' => $versionId,
                'channel' => $channel,
                'fingerprint' => $fingerprint,
                'decision' => $reviewedFingerprint === null ? 'revoked' : 'approved',
                'decided_by' => $curatorId,
                'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}
