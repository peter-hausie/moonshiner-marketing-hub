<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Services\Content\CreateContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BlogExecutionMediaTest extends TestCase
{
    use RefreshDatabase;

    private function execution(): array
    {
        config(['services.moonshiner_blog.media_delivery_enabled' => true]);
        Storage::fake('public');
        Storage::fake('content_snapshots');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        Storage::disk('public')->put('image.png', $bytes);
        $item = ContentItem::factory()->create(['image_path' => 'image.png']);
        $version = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: true);
        $publication = $item->publications()->create(['channel' => 'blog']);
        DB::table('publications')->where('id', $publication->id)->update([
            'content_version_id' => $version, 'status' => 'processing', 'attempts' => 1,
        ]);
        $attempt = DB::table('publication_attempts')->insertGetId([
            'publication_id' => $publication->id, 'content_item_id' => $item->id,
            'content_version_id' => $version, 'attempt_number' => 1,
            'status' => 'sending', 'provenance' => 'test_fixture',
            'started_at' => now(), 'payload_sha256' => str_repeat('a', 64),
        ]);

        return [$attempt, hash('sha256', $bytes), $bytes];
    }

    private function url(int $attempt, string $digest, int $minutes = 2): string
    {
        return URL::temporarySignedRoute('blog.execution-media', now()->addMinutes($minutes), compact('attempt', 'digest'));
    }

    public function test_serves_exact_private_bytes_without_using_mutable_public_image(): void
    {
        [$attempt, $digest, $bytes] = $this->execution();
        Storage::disk('public')->put('image.png', 'mutable draft');
        $this->get($this->url($attempt, $digest))->assertOk()
            ->assertContent($bytes)->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('sending', DB::table('publication_attempts')->find($attempt)->status);
    }

    public function test_unsigned_expired_overlong_and_wrong_binding_requests_cannot_read_media(): void
    {
        [$attempt, $digest] = $this->execution();
        $this->get(route('blog.execution-media', compact('attempt', 'digest')))->assertNotFound();
        $this->get($this->url($attempt, $digest, -1))->assertNotFound();
        $this->get($this->url($attempt, $digest, 6))->assertNotFound();
        $this->get($this->url($attempt + 1, $digest))->assertNotFound();
        $this->get($this->url($attempt, str_repeat('b', 64)))->assertNotFound();
        config(['services.moonshiner_blog.media_delivery_enabled' => false]);
        $this->get($this->url($attempt, $digest))->assertNotFound();
    }

    public function test_terminal_legacy_stale_or_mismatched_execution_is_rejected(): void
    {
        [$attempt, $digest] = $this->execution();
        $original = (array) DB::table('publication_attempts')->find($attempt);
        foreach ([
            ['status' => 'started'], ['status' => 'published'],
            ['finished_at' => now()], ['payload_sha256' => null],
            ['attempt_number' => 2], ['started_at' => now()->subMinutes(6)],
        ] as $mutation) {
            DB::table('publication_attempts')->where('id', $attempt)->update($mutation);
            $this->get($this->url($attempt, $digest))->assertNotFound();
            DB::table('publication_attempts')->where('id', $attempt)->update($original);
        }
        DB::table('publications')->update(['status' => 'failed']);
        $this->get($this->url($attempt, $digest))->assertNotFound();
    }

    public function test_replacement_and_missing_private_snapshot_fail_without_public_fallback(): void
    {
        [$attempt, $digest] = $this->execution();
        $path = Storage::disk('content_snapshots')->path($digest);
        chmod($path, 0600);
        file_put_contents($path, str_repeat('x', filesize($path)));
        chmod($path, 0440);
        $this->get($this->url($attempt, $digest))->assertNotFound();
        Storage::disk('content_snapshots')->delete($digest);
        $this->get($this->url($attempt, $digest))->assertNotFound();
    }
}
