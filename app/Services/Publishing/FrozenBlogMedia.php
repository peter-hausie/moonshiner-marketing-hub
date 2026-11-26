<?php

namespace App\Services\Publishing;

use App\Services\Content\VerifyContentMedia;
use RuntimeException;

/** Verified transport bytes only; neither approval nor permission to expose them. */
final readonly class FrozenBlogMedia
{
    private function __construct(public string $bytes, public string $contentType, public string $sha256) {}

    public static function fromSnapshot(array $media): self
    {
        $bytes = app(VerifyContentMedia::class)->read($media);
        // Match the existing Blog importer's raster formats. Never trust an
        // original filename or serve arbitrary HTML/SVG as an approved image.
        $image = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($image === false || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            || ($image['mime'] ?? null) !== $mime) {
            throw new RuntimeException('Snapshot is not a supported Blog raster image.');
        }

        return new self($bytes, $mime, $media['sha256']);
    }
}
