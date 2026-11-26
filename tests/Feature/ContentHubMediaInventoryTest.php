<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentHubMediaInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function inventory(int $exit = 0): array
    {
        $this->assertSame($exit, Artisan::call('content-hub:media-inventory'));
        $output = Artisan::output();
        $this->assertStringNotContainsString('confidential', $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_media_fingerprint_detects_same_size_changes_without_writes_or_dispatch(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('confidential.jpg', 'first');
        ContentItem::factory()->create(['image_path' => 'confidential.jpg']);
        Bus::fake();
        Http::preventStrayRequests();
        $before = DB::table('content_items')->get()->toJson();
        $first = $this->inventory();
        $this->assertSame(1, $first['verified']);
        $this->assertSame($first, $this->inventory());
        Storage::disk('public')->put('confidential.jpg', 'other');
        $this->assertNotSame($first['sha256'], $this->inventory()['sha256']);
        $this->assertSame($before, DB::table('content_items')->get()->toJson());
        $this->assertSame('other', Storage::disk('public')->get('confidential.jpg'));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_missing_media_fails_instead_of_emitting_a_successful_fingerprint(): void
    {
        Storage::fake('public');
        ContentItem::factory()->create(['image_path' => 'confidential-missing.jpg']);
        $result = $this->inventory(1);
        $this->assertSame(1, $result['missing']);
        $this->assertNull($result['sha256']);
    }

    public function test_traversal_urls_and_symlinks_outside_public_storage_are_rejected(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('local')->put('confidential.txt', 'private');
        symlink(Storage::disk('local')->path('confidential.txt'), Storage::disk('public')->path('link.txt'));
        foreach (['../confidential.txt', '/tmp/confidential.txt', 'https://example.com/confidential', 'link.txt'] as $path) {
            ContentItem::factory()->create(['image_path' => $path]);
        }
        Http::preventStrayRequests();
        $result = $this->inventory(1);
        $this->assertSame(4, $result['unsafe']);
        $this->assertSame(0, $result['verified']);
        $this->assertNull($result['sha256']);
        Http::assertNothingSent();
    }

    public function test_empty_references_and_unreferenced_files_do_not_change_snapshot(): void
    {
        Storage::fake('public');
        ContentItem::factory()->create(['image_path' => null]);
        $before = $this->inventory();
        Storage::disk('public')->put('confidential-unreferenced.jpg', 'not a referenced asset');
        $this->assertSame($before, $this->inventory());
        $this->assertSame(0, $before['references']);
    }

    public function test_remote_storage_is_refused_before_any_network_access(): void
    {
        config(['filesystems.disks.public.driver' => 's3']);
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('content-hub:media-inventory'));
        Http::assertNothingSent();
    }
}
