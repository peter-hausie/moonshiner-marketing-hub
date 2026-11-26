<?php

namespace Tests\Feature;

use App\Services\Content\SnapshotContentMedia;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ContentMediaSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('content_snapshots');
    }

    public function test_captured_bytes_survive_source_replacement_and_identical_captures_reuse_object(): void
    {
        Storage::disk('public')->put('images/a.png', 'original bytes');
        $service = new SnapshotContentMedia;
        $first = $service->create('images/a.png');
        $this->assertSame($first, $service->create('images/a.png'));
        Storage::disk('public')->put('images/a.png', 'replacement');
        $second = $service->create('images/a.png');
        $this->assertNotSame($first['sha256'], $second['sha256']);
        $this->assertSame('original bytes', Storage::disk($first['disk'])->get($first['path']));
        $this->assertSame(14, $first['bytes']);
        $this->assertCount(2, Storage::disk('content_snapshots')->allFiles());
    }

    public function test_unsafe_paths_are_rejected_before_storage_access(): void
    {
        foreach (['', '../secret', '/etc/passwd', 'https://example.org/a', 'a\\b', 'a/./b', 'a//b', "a\0b"] as $path) {
            try {
                (new SnapshotContentMedia)->create($path);
                $this->fail('Unsafe path accepted');
            } catch (InvalidArgumentException) {
                $this->assertSame([], Storage::disk('content_snapshots')->allFiles());
            }
        }
    }

    public function test_symlink_source_is_rejected(): void
    {
        Storage::disk('public')->put('original', 'bytes');
        symlink(Storage::disk('public')->path('original'), Storage::disk('public')->path('alias'));
        $this->expectException(RuntimeException::class);
        (new SnapshotContentMedia)->create('alias');
    }

    public function test_corrupt_existing_object_is_not_overwritten_and_temporary_files_are_removed(): void
    {
        Storage::disk('public')->put('image', 'good');
        $key = hash('sha256', 'good');
        Storage::disk('content_snapshots')->put($key, 'evil');
        try {
            (new SnapshotContentMedia)->create('image');
            $this->fail('Corrupt object accepted');
        } catch (RuntimeException) {
            $this->assertSame('evil', Storage::disk('content_snapshots')->get($key));
            $this->assertSame([$key], Storage::disk('content_snapshots')->allFiles());
        }
    }

    public function test_writable_existing_objects_are_rejected_without_repair_or_replacement(): void
    {
        Storage::disk('public')->put('image', 'original');
        $service = new SnapshotContentMedia;
        $media = $service->create('image');
        $target = Storage::disk('content_snapshots')->path($media['path']);
        foreach ([0600, 0420, 0402] as $mode) {
            chmod($target, $mode);
            $rejected = false;
            try {
                $service->create('image');
            } catch (RuntimeException $exception) {
                $rejected = true;
                $this->assertSame('Existing media snapshot is writable or unreadable.', $exception->getMessage());
                clearstatcache(true, $target);
                $this->assertSame($mode, fileperms($target) & 0777);
                $this->assertSame('original', file_get_contents($target));
                $this->assertSame([$media['path']], Storage::disk('content_snapshots')->allFiles());
            }
            $this->assertTrue($rejected, 'Writable snapshot accepted');
        }
        chmod($target, 0400);
        $this->assertSame($media, $service->create('image'));
    }

    public function test_missing_empty_and_oversized_sources_leave_no_objects(): void
    {
        Storage::disk('public')->put('empty', '');
        $file = fopen(Storage::disk('public')->path('large'), 'wb');
        ftruncate($file, 25 * 1024 * 1024 + 1);
        fclose($file);
        foreach (['missing', 'empty', 'large'] as $path) {
            try {
                (new SnapshotContentMedia)->create($path);
                $this->fail('Invalid source accepted');
            } catch (RuntimeException) {
                $this->assertSame([], Storage::disk('content_snapshots')->allFiles());
            }
        }
    }

    public function test_remote_disks_are_rejected(): void
    {
        config(['filesystems.disks.public.driver' => 's3']);
        $this->expectException(RuntimeException::class);
        (new SnapshotContentMedia)->create('image');
    }

    public function test_public_destination_is_rejected(): void
    {
        Storage::disk('public')->put('image', 'bytes');
        config(['filesystems.disks.content_snapshots.root' => Storage::disk('public')->path('snapshots')]);
        Storage::forgetDisk('content_snapshots');
        $this->expectException(RuntimeException::class);
        (new SnapshotContentMedia)->create('image');
    }
}
