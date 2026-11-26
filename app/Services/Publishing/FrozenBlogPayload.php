<?php

namespace App\Services\Publishing;

/** Internal value object, not an authorization token. Built under execution locks. */
final readonly class FrozenBlogPayload
{
    public function __construct(public array $data, public ?string $mediaDigest = null) {}

    public function fingerprint(): string
    {
        // Preserve existing text execution fingerprints; URLs never enter durable data.
        $canonical = $this->mediaDigest === null ? $this->data : [$this->data, 'media_sha256' => $this->mediaDigest];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
