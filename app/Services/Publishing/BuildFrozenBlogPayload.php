<?php

namespace App\Services\Publishing;

use RuntimeException;
use stdClass;

/** Call under the reviewed version's execution locks; this does not grant admission. */
final class BuildFrozenBlogPayload
{
    public function build(stdClass $version, ?string $scheduledAt): FrozenBlogPayload
    {
        $digest = null;
        if ($version->media_snapshot !== null) {
            $snapshot = json_decode($version->media_snapshot, true);
            if (! is_array($snapshot)) {
                throw new RuntimeException('Invalid execution media snapshot.');
            }
            // Verify actual immutable bytes before fingerprinting an execution.
            // Retain only the digest, never bytes, paths or signed URLs in the payload.
            $digest = FrozenBlogMedia::fromSnapshot($snapshot)->sha256;
        } elseif ($version->image_path !== null) {
            throw new RuntimeException('Reviewed media requires an immutable snapshot.');
        }

        return new FrozenBlogPayload([
            'source_id' => (string) $version->content_item_id,
            'title' => $version->title,
            'body' => $version->body,
            'context' => $version->context,
            'image_url' => null,
            'scheduled_at' => $scheduledAt,
        ], $digest);
    }
}
