<?php

namespace App\Services\Content;

use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ChangePublicationBinding
{
    /** Internal only: actor identity must come from an authenticated human session. */
    public function change(
        int $publicationId,
        int $curatorId,
        int $versionId,
        string $channel,
        ?int $expectedVersionId,
        string $expectedChannel,
    ): void {
        DB::transaction(function () use ($publicationId, $curatorId, $versionId, $channel, $expectedVersionId, $expectedChannel): void {
            $actor = DB::table('users')->where('id', $curatorId)->lockForUpdate()->first();
            if ($actor === null || $actor->role !== UserRole::Curator->value) {
                throw new AuthorizationException('A current human curator is required.');
            }
            $publication = DB::table('publications')->where('id', $publicationId)->lockForUpdate()->firstOrFail();
            if ($publication->content_version_id !== $expectedVersionId || $publication->channel !== $expectedChannel) {
                throw new RuntimeException('The publication binding has changed; reload before editing.');
            }
            if ($publication->status !== PublicationStatus::Pending->value) {
                throw new RuntimeException('Only pending publications can be rebound.');
            }
            if (trim($channel) === '' || strlen($channel) > 255) {
                throw new RuntimeException('A nonempty channel of at most 255 bytes is required.');
            }
            $latest = DB::table('publication_reviews')->where('publication_id', $publicationId)
                ->orderByDesc('id')->lockForUpdate()->first();
            DB::table('content_versions')->where('id', $versionId)
                ->where('content_item_id', $publication->content_item_id)->lockForUpdate()->firstOrFail();

            if ($publication->content_version_id === $versionId && $publication->channel === $channel) {
                return;
            }
            // Reuse the existing append-only revocation, including its original identity.
            // No media read: invalid old media must never prevent invalidating approval.
            if ($latest !== null && $latest->decision === 'approved') {
                app(RecordPublicationReview::class)->revoke($publicationId, $curatorId);
            }
            DB::table('publications')->where('id', $publicationId)->update([
                'content_version_id' => $versionId,
                'channel' => $channel,
                'updated_at' => now(),
            ]);
        }, attempts: 3);
    }
}
