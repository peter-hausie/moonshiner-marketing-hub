<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Services\Content\CreateContentVersion;
use App\Services\Content\PublicationReviewFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PublicationReviewFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $item = ContentItem::factory()->create(['image_path' => null, 'context' => null]);
        $version = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $publication = DB::table('publications')->insertGetId([
            'content_item_id' => $item->id, 'content_version_id' => $version, 'channel' => 'blog',
        ]);

        return [$item, $version, $publication];
    }

    public function test_reads_persisted_snapshot_without_mutation_dispatch_or_http(): void
    {
        [$item, $version, $publication] = $this->fixture();
        $service = app(PublicationReviewFingerprint::class);
        Bus::fake();
        Http::fake();
        $before = DB::table('publications')->get()->toJson();
        $hash = $service->forPublication($publication);
        DB::table('content_items')->where('id', $item->id)->update(['body' => 'Later legacy draft']);
        $this->assertSame($hash, $service->forPublication($publication));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->assertSame($before, DB::table('publications')->get()->toJson());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_text_channel_and_rebinding_each_change_review_identity(): void
    {
        [$item, $version, $publication] = $this->fixture();
        $service = app(PublicationReviewFingerprint::class);
        foreach (['title' => 'new title', 'body' => 'new body', 'context' => '', 'content_type' => 'new type'] as $field => $value) {
            $before = $service->forPublication($publication);
            DB::table('content_versions')->where('id', $version)->update([$field => $value]);
            $this->assertNotSame($before, $service->forPublication($publication), $field);
        }
        $before = $service->forPublication($publication);
        DB::table('publications')->where('id', $publication)->update(['channel' => 'linkedin']);
        $this->assertNotSame($before, $service->forPublication($publication));
        $before = $service->forPublication($publication);
        $next = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        DB::table('publications')->where('id', $publication)->update(['content_version_id' => $next]);
        $this->assertNotSame($before, $service->forPublication($publication));
    }

    public function test_media_is_canonical_but_changed_bytes_change_identity(): void
    {
        [, $version, $publication] = $this->fixture();
        $service = app(PublicationReviewFingerprint::class);
        $media = ['disk' => 'content_snapshots', 'path' => hash('sha256', 'one'), 'sha256' => hash('sha256', 'one'), 'bytes' => 3];
        $set = fn ($value) => DB::table('content_versions')->where('id', $version)->update(['media_snapshot' => json_encode($value)]);
        $set($media);
        $hash = $service->forPublication($publication);
        $set(array_reverse($media, true));
        $this->assertSame($hash, $service->forPublication($publication));
        $media['path'] = $media['sha256'] = hash('sha256', 'two');
        $set($media);
        $this->assertNotSame($hash, $service->forPublication($publication));
        $media['bytes'] = '3';
        $set($media);
        $this->expectException(RuntimeException::class);
        $service->forPublication($publication);
    }

    public function test_unbound_and_legacy_media_fail_closed(): void
    {
        [, $version, $publication] = $this->fixture();
        $service = app(PublicationReviewFingerprint::class);
        DB::table('publications')->where('id', $publication)->update(['content_version_id' => null]);
        try {
            $service->forPublication($publication);
            $this->fail('Unbound publication accepted');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('publications', 1);
        }
        DB::table('publications')->where('id', $publication)->update(['content_version_id' => $version]);
        DB::table('content_versions')->where('id', $version)->update(['image_path' => 'mutable.png']);
        $this->expectException(RuntimeException::class);
        $service->forPublication($publication);
    }

    public function test_verified_review_reads_private_bytes_without_repair_or_public_source(): void
    {
        Storage::fake('public');
        Storage::fake('content_snapshots');
        [$item, , $publication] = $this->fixture();
        Storage::disk('public')->put('image', 'original');
        DB::table('content_items')->where('id', $item->id)->update(['image_path' => 'image']);
        $version = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        DB::table('publications')->where('id', $publication)->update(['content_version_id' => $version]);
        $service = app(PublicationReviewFingerprint::class);
        $hash = $service->forPublication($publication);
        Storage::disk('public')->delete('image');
        Bus::fake();
        Http::fake();
        $before = DB::table('publications')->get()->toJson();
        $this->assertSame($hash, $service->forVerifiedPublication($publication));
        $path = Storage::disk('content_snapshots')->path(hash('sha256', 'original'));
        foreach (['writable', 'corrupt', 'truncated', 'symlink', 'missing'] as $case) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            if ($case === 'symlink') {
                Storage::disk('public')->put('alias-target', 'original');
                symlink(Storage::disk('public')->path('alias-target'), $path);
            } elseif ($case !== 'missing') {
                file_put_contents($path, match ($case) {
                    'corrupt' => 'tampered', 'truncated' => 'x', default => 'original'
                });
                chmod($path, $case === 'writable' ? 0600 : 0400);
            }
            try {
                $service->forVerifiedPublication($publication);
                $this->fail('Unsafe snapshot accepted: '.$case);
            } catch (RuntimeException) {
                $this->assertSame($hash, $service->forPublication($publication));
                $this->assertSame($before, DB::table('publications')->get()->toJson());
                if ($case === 'missing') {
                    $this->assertFileDoesNotExist($path);
                } elseif ($case === 'symlink') {
                    $this->assertTrue(is_link($path));
                } else {
                    $this->assertSame($case === 'writable' ? 'original' : ($case === 'corrupt' ? 'tampered' : 'x'), file_get_contents($path));
                }
            }
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_invalid_persisted_json_is_rejected_without_side_effects(): void
    {
        [, $version, $publication] = $this->fixture();
        $service = app(PublicationReviewFingerprint::class);
        Bus::fake();
        Http::fake();
        foreach (['null', '{broken', 'false', '0', '"text"', '[]', '{}'] as $json) {
            DB::table('content_versions')->where('id', $version)->update(['media_snapshot' => $json]);
            $before = DB::table('content_versions')->get()->toJson();
            $publications = DB::table('publications')->get()->toJson();
            foreach (['forPublication', 'forVerifiedPublication'] as $method) {
                try {
                    $service->$method($publication);
                    $this->fail('Invalid persisted metadata accepted: '.$json);
                } catch (RuntimeException $exception) {
                    $this->assertSame('Review requires canonical snapshot metadata.', $exception->getMessage());
                    $this->assertSame($before, DB::table('content_versions')->get()->toJson());
                    $this->assertSame($publications, DB::table('publications')->get()->toJson());
                }
            }
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_verified_text_only_review_needs_no_media_storage(): void
    {
        [, , $publication] = $this->fixture();
        $service = app(PublicationReviewFingerprint::class);
        $this->assertSame($service->forPublication($publication), $service->forVerifiedPublication($publication));
    }
}
