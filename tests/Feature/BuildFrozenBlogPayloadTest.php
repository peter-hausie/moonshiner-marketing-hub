<?php

namespace Tests\Feature;

use App\Services\Content\SnapshotContentMedia;
use App\Services\Publishing\BlogTransportCapabilities;
use App\Services\Publishing\BuildFrozenBlogPayload;
use App\Services\Publishing\FrozenBlogPayload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BuildFrozenBlogPayloadTest extends TestCase
{
    private function version(): \stdClass
    {
        return (object) ['content_item_id' => 17, 'title' => 'Reviewed title',
            'body' => 'Reviewed body', 'context' => null, 'media_snapshot' => null, 'image_path' => null];
    }

    public function test_text_fingerprint_remains_compatible_with_existing_claims(): void
    {
        $expected = new FrozenBlogPayload(['source_id' => '17', 'title' => 'Reviewed title',
            'body' => 'Reviewed body', 'context' => null, 'image_url' => null,
            'scheduled_at' => '2026-10-17T10:00:00+00:00']);
        $actual = app(BuildFrozenBlogPayload::class)->build($this->version(), $expected->data['scheduled_at']);
        $this->assertSame($expected->data, $actual->data);
        $this->assertSame($expected->fingerprint(), $actual->fingerprint());
        $this->assertNull($actual->mediaDigest);
    }

    public function test_media_binds_verified_bytes_without_retaining_paths_urls_or_bytes_and_does_not_enable_admission(): void
    {
        Http::fake();
        Storage::fake('public');
        Storage::fake('content_snapshots');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        Storage::disk('public')->put('reviewed.png', $bytes);
        $snapshot = app(SnapshotContentMedia::class)->create('reviewed.png');
        $version = $this->version();
        $version->image_path = 'reviewed.png';
        $version->media_snapshot = json_encode($snapshot);
        Storage::disk('public')->put('reviewed.png', 'later mutable edit');
        $payload = app(BuildFrozenBlogPayload::class)->build($version, null);
        $this->assertSame(hash('sha256', $bytes), $payload->mediaDigest);
        $this->assertNull($payload->data['image_url']);
        $this->assertSame(['data', 'mediaDigest'], array_keys(get_object_vars($payload)));
        $this->assertStringNotContainsString('reviewed.png', json_encode($payload));
        $this->assertArrayNotHasKey('path', $payload->data);
        $this->assertArrayNotHasKey('media_snapshot', $payload->data);
        $this->assertNotSame((new FrozenBlogPayload($payload->data))->fingerprint(), $payload->fingerprint());
        $this->assertFalse(app(BlogTransportCapabilities::class)->supportsVersion($version));
        Storage::disk('content_snapshots')->delete($snapshot['path']);
        try {
            app(BuildFrozenBlogPayload::class)->build($version, null);
            $this->fail('Missing snapshot accepted');
        } catch (RuntimeException) {
            $this->assertSame(hash('sha256', $bytes), $payload->mediaDigest);
        }
        Http::assertNothingSent();
    }

    public function test_rejects_legacy_media_and_malformed_snapshots_instead_of_silently_omitting_them(): void
    {
        foreach ([null, 'invalid-json', 'null', '"string"', '42'] as $snapshot) {
            $version = $this->version();
            $version->image_path = 'legacy.png';
            $version->media_snapshot = $snapshot;
            try {
                app(BuildFrozenBlogPayload::class)->build($version, null);
                $this->fail('Invalid media accepted');
            } catch (RuntimeException $exception) {
                $this->assertContains($exception->getMessage(), [
                    'Reviewed media requires an immutable snapshot.', 'Invalid execution media snapshot.',
                ]);
            }
        }
    }
}
