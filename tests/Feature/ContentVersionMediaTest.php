<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Services\Content\CreateContentVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ContentVersionMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('content_snapshots');
    }

    public function test_explicit_capture_binds_original_bytes_and_does_not_modify_old_versions_or_piece(): void
    {
        $item = ContentItem::factory()->create(['image_path' => 'image.png']);
        $before = $item->fresh()->getRawOriginal();
        $service = app(CreateContentVersion::class);
        $legacy = $service->create($item->id, $item->submitted_by, 'human_snapshot');
        Storage::disk('public')->put('image.png', 'original');
        $id = $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        $version = DB::table('content_versions')->find($id);
        $media = json_decode($version->media_snapshot, true, flags: JSON_THROW_ON_ERROR);
        Storage::disk('public')->put('image.png', 'replacement');
        $this->assertSame(['disk' => 'content_snapshots', 'path' => hash('sha256', 'original'), 'sha256' => hash('sha256', 'original'), 'bytes' => 8], $media);
        $this->assertSame('original', Storage::disk($media['disk'])->get($media['path']));
        $this->assertNull(DB::table('content_versions')->find($legacy)->media_snapshot);
        $this->assertSame($before, $item->fresh()->getRawOriginal());
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_missing_media_aborts_version_without_consuming_number(): void
    {
        $item = ContentItem::factory()->create(['image_path' => 'missing.png']);
        $service = app(CreateContentVersion::class);
        try {
            $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
            $this->fail('Missing media accepted');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('content_versions', 0);
        }
        Storage::disk('public')->put('missing.png', 'bytes');
        $id = $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        $this->assertSame(1, DB::table('content_versions')->find($id)->version_number);
    }

    public function test_database_failure_retains_object_and_retry_reuses_it_without_deleting_shared_bytes(): void
    {
        $item = ContentItem::factory()->create(['image_path' => 'image.png']);
        Storage::disk('public')->put('image.png', 'shared');
        $service = app(CreateContentVersion::class);
        try {
            $service->create($item->id, 999999, 'human_snapshot', snapshotMedia: true);
            $this->fail('Invalid author accepted');
        } catch (QueryException) {
            $this->assertDatabaseCount('content_versions', 0);
            $this->assertCount(1, Storage::disk('content_snapshots')->allFiles());
        }
        $id = $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        $snapshot = DB::table('content_versions')->find($id);
        DB::beginTransaction();
        $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        DB::rollBack();
        $this->assertDatabaseCount('content_versions', 1);
        $this->assertEquals($snapshot, DB::table('content_versions')->find($id));
        $this->assertSame('shared', Storage::disk('content_snapshots')->get(hash('sha256', 'shared')));
        $this->assertCount(1, Storage::disk('content_snapshots')->allFiles());
    }

    public function test_writable_shared_object_aborts_new_version_and_preserves_existing_history(): void
    {
        $item = ContentItem::factory()->create(['image_path' => 'image.png']);
        Storage::disk('public')->put('image.png', 'shared');
        $service = app(CreateContentVersion::class);
        $id = $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        $before = DB::table('content_versions')->find($id);
        $path = Storage::disk('content_snapshots')->path(hash('sha256', 'shared'));
        chmod($path, 0600);
        $rejected = false;
        try {
            $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        } catch (RuntimeException $exception) {
            $rejected = true;
            $this->assertSame('Existing media snapshot is writable or unreadable.', $exception->getMessage());
        }
        $this->assertTrue($rejected);
        $this->assertDatabaseCount('content_versions', 1);
        $this->assertEquals($before, DB::table('content_versions')->find($id));
        $this->assertSame('shared', file_get_contents($path));
        chmod($path, 0400);
        $next = $service->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        $this->assertSame(2, DB::table('content_versions')->find($next)->version_number);
    }

    public function test_no_image_is_explicitly_supported_without_inventing_media_evidence(): void
    {
        $item = ContentItem::factory()->create(['image_path' => null]);
        $id = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        $this->assertNull(DB::table('content_versions')->find($id)->media_snapshot);
        $this->assertSame([], Storage::disk('content_snapshots')->allFiles());
    }
}
