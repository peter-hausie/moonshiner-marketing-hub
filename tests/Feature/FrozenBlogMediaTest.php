<?php

namespace Tests\Feature;

use App\Services\Content\SnapshotContentMedia;
use App\Services\Publishing\FrozenBlogMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FrozenBlogMediaTest extends TestCase
{
    private function snapshot(string $bytes): array
    {
        Storage::fake('public');
        Storage::fake('content_snapshots');
        Storage::disk('public')->put('misleading.html', $bytes);

        return app(SnapshotContentMedia::class)->create('misleading.html');
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    }

    public function test_freezes_verified_image_bytes_and_detects_type_without_filename_or_http(): void
    {
        Http::fake();
        $media = $this->snapshot($this->png());
        $frozen = FrozenBlogMedia::fromSnapshot($media);
        $path = Storage::disk('content_snapshots')->path($media['path']);
        chmod($path, 0600);
        file_put_contents($path, 'later replacement');
        Storage::disk('public')->put('misleading.html', 'changed draft');
        $this->assertSame($this->png(), $frozen->bytes);
        $this->assertSame('image/png', $frozen->contentType);
        $this->assertSame(hash('sha256', $this->png()), $frozen->sha256);
        Http::assertNothingSent();
    }

    public function test_rejects_tampered_bytes_before_transport(): void
    {
        $media = $this->snapshot($this->png());
        $path = Storage::disk('content_snapshots')->path($media['path']);
        chmod($path, 0600);
        file_put_contents($path, str_repeat('x', $media['bytes']));
        chmod($path, 0440);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Snapshot bytes or identity failed verification.');
        FrozenBlogMedia::fromSnapshot($media);
    }

    public function test_rejects_verified_non_image_and_svg_bytes(): void
    {
        foreach (['<html>not an image</html>', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'GIF89a'] as $bytes) {
            $media = $this->snapshot($bytes);
            try {
                FrozenBlogMedia::fromSnapshot($media);
                $this->fail('Unsupported bytes accepted');
            } catch (RuntimeException $exception) {
                $this->assertSame('Snapshot is not a supported Blog raster image.', $exception->getMessage());
            }
        }
    }

    public function test_rejects_missing_snapshot_without_falling_back_to_public_image(): void
    {
        $media = $this->snapshot($this->png());
        Storage::disk('content_snapshots')->delete($media['path']);
        $this->expectException(RuntimeException::class);
        FrozenBlogMedia::fromSnapshot($media);
    }
}
