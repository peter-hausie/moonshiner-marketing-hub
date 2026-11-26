<?php

namespace App\Services\Publishing;

use stdClass;

/** Transport support only: callers must independently verify human approval. */
final class BlogTransportCapabilities
{
    /** Deployment evidence, not provider discovery or human editorial approval. */
    public function supportsImmutableMediaDestination(): bool
    {
        $evidence = config('services.moonshiner_blog.immutable_media_destination');
        $endpoint = config('services.moonshiner_blog.url');

        return is_array($evidence)
            && ($evidence['contract'] ?? null) === 'content-addressed-sha256-v1'
            && is_string($endpoint)
            && filter_var($endpoint, FILTER_VALIDATE_URL) !== false
            && parse_url($endpoint, PHP_URL_SCHEME) === 'https'
            && ($evidence['endpoint'] ?? null) === $endpoint;
    }

    public function supportsVersion(stdClass $version): bool
    {
        // No mutable URL fallback or silent omission of reviewed image bytes.
        return $version->media_snapshot === null && $version->image_path === null;
    }
}
