<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Services\Content\CreateContentVersion;
use App\Services\Publishing\BlogPublisher;
use App\Services\Publishing\FrozenBlogPayload;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class BlogPublisherMediaTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    private function execution(): array
    {
        config(['services.moonshiner_blog.media_delivery_enabled' => true,
            'services.moonshiner_blog.url' => 'https://blog.example.test/api',
            'services.moonshiner_blog.immutable_media_destination' => [
                'endpoint' => 'https://blog.example.test/api',
                'contract' => 'content-addressed-sha256-v1',
            ],
            'services.moonshiner_blog.token' => 'test-fixture']);
        URL::forceScheme('https');
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
        $payload = new FrozenBlogPayload(['title' => 'Reviewed title', 'image_url' => null], hash('sha256', $bytes));
        $attempt = DB::table('publication_attempts')->insertGetId([
            'publication_id' => $publication->id, 'content_item_id' => $item->id,
            'content_version_id' => $version, 'attempt_number' => 1,
            'status' => 'started', 'provenance' => 'test_fixture',
            'started_at' => now(), 'payload_sha256' => $payload->fingerprint(),
        ]);

        return [$publication, $payload, $attempt, $bytes];
    }

    public function test_materializes_url_only_for_committed_execution_and_serves_exact_bytes(): void
    {
        [$publication, $payload, $attempt, $bytes] = $this->execution();
        Http::fake(function ($request) use ($attempt, $bytes) {
            $this->assertSame('sending', DB::table('publication_attempts')->find($attempt)->status);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue(str_starts_with($request['image_url'], 'https://'));
            $this->get($request['image_url'])->assertOk()->assertContent($bytes);

            return Http::response(['id' => 123, 'url' => 'https://blog.example.test/post']);
        });
        app(BlogPublisher::class)->publish($publication, $payload, $attempt);
        $this->assertNull($payload->data['image_url']);
        $this->assertSame($payload->fingerprint(), DB::table('publication_attempts')->find($attempt)->payload_sha256);
        try {
            app(BlogPublisher::class)->publish($publication, $payload, $attempt);
            $this->fail('Replay accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Execution attempt is not eligible for this payload.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_redirect_cannot_forward_media_or_masquerade_as_success(): void
    {
        [$publication, $payload, $attempt] = $this->execution();
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);

            // Even a plausible success body must not turn a redirect into success.
            return Http::response(['id' => 123, 'url' => 'https://blog.example.test/post'], 307, [
                'Location' => 'https://other.example.test/posts',
            ]);
        });
        try {
            app(BlogPublisher::class)->publish($publication, $payload, $attempt);
            $this->fail('Redirect accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Blog media transport outcome is unknown.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('sending', DB::table('publication_attempts')->find($attempt)->status);
        try {
            app(BlogPublisher::class)->publish($publication, $payload, $attempt);
            $this->fail('Redirect replay accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Execution attempt is not eligible for this payload.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_text_transport_rejects_non_success_without_echoing_response(): void
    {
        [$publication, $mediaPayload, $attempt] = $this->execution();
        $payload = new FrozenBlogPayload($mediaPayload->data);
        DB::table('publication_attempts')->where('id', $attempt)->update(['payload_sha256' => $payload->fingerprint()]);
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['id' => 123, 'url' => 'https://blog.example.test/post',
                'echo' => 'private-provider-diagnostic'], 302, ['Location' => 'https://other.example.test/posts']);
        });
        try {
            app(BlogPublisher::class)->publish($publication, $payload, $attempt);
            $this->fail('Text redirect accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Blog transport outcome is unknown.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('sending', DB::table('publication_attempts')->find($attempt)->status);
        Http::assertSentCount(1);
    }

    public function test_text_http_and_connection_failures_do_not_escape_into_queue_diagnostics(): void
    {
        foreach ([401, 500, 'connection'] as $failure) {
            // Each case needs an independent fake registry and request counter.
            Http::swap(new Factory);
            [$publication, $mediaPayload, $attempt] = $this->execution();
            $payload = new FrozenBlogPayload($mediaPayload->data);
            DB::table('publication_attempts')->where('id', $attempt)->update(['payload_sha256' => $payload->fingerprint()]);
            $requests = 0;
            Http::fake(function () use ($failure, &$requests) {
                $requests++;
                if ($failure === 'connection') {
                    throw new ConnectionException('private-request-diagnostic');
                }

                return Http::response(['error' => 'private-provider-diagnostic'], $failure);
            });
            try {
                app(BlogPublisher::class)->publish($publication, $payload, $attempt);
                $this->fail('Failed transport accepted');
            } catch (RuntimeException $exception) {
                $this->assertSame('Blog transport outcome is unknown.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
                $this->assertStringNotContainsString('private-', (string) $exception);
            }
            $this->assertSame('sending', DB::table('publication_attempts')->find($attempt)->status);
            try {
                app(BlogPublisher::class)->publish($publication, $payload, $attempt);
                $this->fail('Failed transport replay accepted');
            } catch (RuntimeException $exception) {
                $this->assertSame('Execution attempt is not eligible for this payload.', $exception->getMessage());
            }
            $this->assertSame(1, $requests);
        }
    }

    public function test_disabled_insecure_wrong_digest_and_corrupt_media_never_send(): void
    {
        [$publication, $payload, $attempt] = $this->execution();
        Http::fake();
        foreach (['disabled', 'http', 'digest', 'corrupt'] as $case) {
            config(['services.moonshiner_blog.media_delivery_enabled' => $case !== 'disabled']);
            URL::forceScheme($case === 'http' ? 'http' : 'https');
            $candidate = $case === 'digest' ? new FrozenBlogPayload($payload->data, str_repeat('b', 64)) : $payload;
            DB::table('publication_attempts')->where('id', $attempt)->update(['payload_sha256' => $candidate->fingerprint()]);
            if ($case === 'corrupt') {
                $path = Storage::disk('content_snapshots')->path($payload->mediaDigest);
                chmod($path, 0600);
                file_put_contents($path, 'corrupt');
                chmod($path, 0440);
            }
            try {
                app(BlogPublisher::class)->publish($publication, $candidate, $attempt);
                $this->fail('Invalid media accepted');
            } catch (RuntimeException) {
                $this->assertSame('started', DB::table('publication_attempts')->find($attempt)->status);
            }
        }
        Http::assertNothingSent();
    }

    public function test_unverified_or_different_destination_cannot_consume_media_attempt(): void
    {
        [$publication, $payload, $attempt] = $this->execution();
        Http::fake();
        $original = (array) DB::table('publication_attempts')->find($attempt);
        foreach ([null, true, [],
            ['endpoint' => 'https://blog.example.test/api', 'contract' => 'mutable-source-v1'],
            ['endpoint' => 'https://other.example.test/api', 'contract' => 'content-addressed-sha256-v1'],
        ] as $evidence) {
            config(['services.moonshiner_blog.immutable_media_destination' => $evidence]);
            try {
                app(BlogPublisher::class)->publish($publication, $payload, $attempt);
                $this->fail('Unverified destination accepted');
            } catch (RuntimeException $exception) {
                $this->assertSame('Immutable Blog media destination is not verified.', $exception->getMessage());
                $this->assertSame($original, (array) DB::table('publication_attempts')->find($attempt));
            }
        }
        config(['services.moonshiner_blog.url' => 'http://blog.example.test/api',
            'services.moonshiner_blog.immutable_media_destination' => [
                'endpoint' => 'http://blog.example.test/api', 'contract' => 'content-addressed-sha256-v1',
            ]]);
        try {
            app(BlogPublisher::class)->publish($publication, $payload, $attempt);
            $this->fail('Insecure destination accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Immutable Blog media destination is not verified.', $exception->getMessage());
            $this->assertSame($original, (array) DB::table('publication_attempts')->find($attempt));
        }
        Http::assertNothingSent();
    }

    public function test_echoed_access_material_does_not_escape_in_transport_exception(): void
    {
        [$publication, $payload, $attempt] = $this->execution();
        Http::fake(fn ($request) => Http::response(['echo' => $request['image_url']], 500));
        try {
            app(BlogPublisher::class)->publish($publication, $payload, $attempt);
            $this->fail('Failed transport accepted');
        } catch (RuntimeException $exception) {
            $this->assertSame('Blog media transport outcome is unknown.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('sending', DB::table('publication_attempts')->find($attempt)->status);
        Http::assertSentCount(1);
    }
}
