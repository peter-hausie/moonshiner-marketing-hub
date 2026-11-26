<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\PublishPublication;
use App\Models\ContentItem;
use App\Models\PublicationAttempt;
use App\Models\User;
use App\Services\Content\CreateContentVersion;
use App\Services\Content\PublicationReviewFingerprint;
use App\Services\Content\RecordPublicationReview;
use App\Services\Publishing\BlogPublisher;
use App\Services\Publishing\FrozenBlogPayload;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PublicationExecutionTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        // In-memory DB and real commit boundaries: rebuild without outer transaction.
        RefreshDatabaseState::$migrated = false;
    }

    private function approved(?string $imagePath = null): array
    {
        $item = ContentItem::factory()->create(['image_path' => $imagePath]);
        $publication = $item->publications()->create(['channel' => 'blog']);
        $version = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot', snapshotMedia: $imagePath !== null);
        DB::table('publications')->where('id', $publication->id)->update(['content_version_id' => $version]);
        $curator = User::factory()->create(['role' => UserRole::Curator]);
        $review = app(RecordPublicationReview::class)->approve(
            $publication->id, $curator->id,
            app(PublicationReviewFingerprint::class)->forVerifiedPublication($publication->id),
        );

        return [$publication, $version, $review];
    }

    public function test_http_uses_frozen_reviewed_text_despite_legacy_and_post_claim_version_edits(): void
    {
        [$publication, $version] = $this->approved();
        $snapshot = DB::table('content_versions')->find($version);
        config(['services.moonshiner_blog.url' => 'https://blog.test/api', 'services.moonshiner_blog.token' => 'test-only']);
        Http::fake(['*' => Http::response(['id' => 42, 'url' => 'https://blog.test/post'])]);
        // Bypass legacy model synchronization to simulate independent draft edits.
        DB::table('content_items')->where('id', $publication->content_item_id)->update([
            'title' => 'unreviewed draft', 'body' => 'unreviewed body',
            'context' => 'unreviewed context', 'image_path' => 'unreviewed.jpg',
        ]);
        PublicationAttempt::created(function () use ($version): void {
            // Even a privileged writer changing the snapshot after capture cannot
            // change the value object sent outside the claim transaction.
            DB::table('content_versions')->where('id', $version)->update([
                'title' => 'post-capture edit', 'body' => 'post-capture body', 'context' => 'post-capture context',
            ]);
        });
        try {
            (new PublishPublication($publication->id))->handle(app(BlogPublisher::class));
        } finally {
            PublicationAttempt::flushEventListeners();
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data() === [
            'source_id' => (string) $publication->content_item_id,
            'title' => $snapshot->title, 'body' => $snapshot->body, 'context' => $snapshot->context,
            'image_url' => null, 'scheduled_at' => null,
        ]);
        $this->assertDatabaseHas('publication_attempts', ['content_version_id' => $version, 'status' => 'published']);
    }

    public function test_approved_media_waits_without_claim_or_legacy_url_fallback(): void
    {
        Storage::fake('public');
        Storage::fake('content_snapshots');
        Storage::disk('public')->put('approved.jpg', 'approved image bytes');
        [$publication] = $this->approved('approved.jpg');
        $before = $publication->fresh()->getRawOriginal();
        Http::fake();
        (new PublishPublication($publication->id))->handle(app(BlogPublisher::class));
        Http::assertNothingSent();
        $this->assertSame($before, $publication->fresh()->getRawOriginal());
        $this->assertDatabaseCount('publication_attempts', 0);
    }

    public function test_dispatch_skips_approved_media_without_changes_and_still_enqueues_text(): void
    {
        Storage::fake('public');
        Storage::fake('content_snapshots');
        Storage::disk('public')->put('approved.jpg', 'approved image bytes');
        [$media] = $this->approved('approved.jpg');
        [$text] = $this->approved();
        $before = $media->fresh()->getRawOriginal();
        $reviews = DB::table('publication_reviews')->get()->toArray();
        Queue::fake();
        Http::fake();

        $this->artisan('publications:dispatch')
            ->expectsOutput('Waiting for immutable media transport: 1 publication(s).')
            ->assertSuccessful();
        Queue::assertPushed(PublishPublication::class, 1);
        Queue::assertPushed(PublishPublication::class, fn ($job) => $job->publicationId === $text->id);
        $this->assertSame($before, $media->fresh()->getRawOriginal());
        $this->assertEquals($reviews, DB::table('publication_reviews')->get()->toArray());
        $this->assertDatabaseCount('publication_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_direct_legacy_publisher_call_cannot_read_mutable_draft(): void
    {
        [$publication] = $this->approved();
        Http::fake();
        try {
            app(BlogPublisher::class)->publish($publication);
            $this->fail('Legacy publisher invocation must fail closed');
        } catch (RuntimeException $exception) {
            $this->assertSame('A frozen execution payload is required.', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('publication_attempts', 0);
    }

    public function test_execution_boundary_rejects_forged_payload_and_direct_replays(): void
    {
        [$publication] = $this->approved();
        config(['services.moonshiner_blog.url' => 'https://blog.test/api', 'services.moonshiner_blog.token' => 'test-only']);
        Http::fake(['*' => Http::response(['id' => 42, 'url' => 'https://blog.test/post'])]);
        $boundary = app(BlogPublisher::class);
        $publisher = Mockery::mock(BlogPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturnUsing(function ($claimed, $payload, $attemptId) use ($boundary) {
            $attemptBefore = PublicationAttempt::findOrFail($attemptId)->getRawOriginal();
            $forged = new FrozenBlogPayload(array_replace($payload->data, ['title' => 'forged']));
            foreach ([[$forged, $attemptId], [$payload, null], [$payload, $attemptId + 1000]] as [$candidate, $id]) {
                try {
                    $boundary->publish($claimed, $candidate, $id);
                    $this->fail('Unauthorized execution must fail');
                } catch (RuntimeException) {
                    Http::assertNothingSent();
                    $this->assertSame($attemptBefore, PublicationAttempt::findOrFail($attemptId)->getRawOriginal());
                }
            }
            foreach ([['payload_sha256' => null], ['attempt_number' => 99], ['status' => 'unknown']] as $mutation) {
                DB::table('publication_attempts')->where('id', $attemptId)->update($mutation);
                try {
                    $boundary->publish($claimed, $payload, $attemptId);
                    $this->fail('Legacy or mismatched attempts must not execute');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Execution attempt is not eligible for this payload.', $exception->getMessage());
                    Http::assertNothingSent();
                } finally {
                    DB::table('publication_attempts')->where('id', $attemptId)->update($attemptBefore);
                }
            }
            DB::beginTransaction();
            try {
                $boundary->publish($claimed, $payload, $attemptId);
                $this->fail('HTTP must not run in caller transaction');
            } catch (RuntimeException $exception) {
                $this->assertSame('A committed execution attempt is required.', $exception->getMessage());
                Http::assertNothingSent();
            } finally {
                DB::rollBack();
            }
            $result = $boundary->publish($claimed, $payload, $attemptId);
            $this->assertSame('sending', PublicationAttempt::findOrFail($attemptId)->status);
            try {
                $boundary->publish($claimed, $payload, $attemptId);
                $this->fail('Direct replay must fail even before outcome persistence');
            } catch (RuntimeException $exception) {
                $this->assertSame('Execution attempt is not eligible for this payload.', $exception->getMessage());
            }
            Http::assertSentCount(1);

            return $result;
        });
        (new PublishPublication($publication->id))->handle($publisher);
        $this->assertSame('published', PublicationAttempt::sole()->status);
    }

    public function test_real_http_success_followed_by_storage_failure_cannot_be_replayed(): void
    {
        [$publication] = $this->approved();
        config(['services.moonshiner_blog.url' => 'https://blog.test/api', 'services.moonshiner_blog.token' => 'test-only']);
        $payload = null;
        Http::fake(function ($request) use (&$payload) {
            $payload = new FrozenBlogPayload($request->data());
            $this->assertSame('sending', PublicationAttempt::sole()->status);

            return Http::response(['id' => 42, 'url' => 'https://blog.test/post']);
        });
        PublicationAttempt::updating(fn () => throw new RuntimeException('outcome storage failed'));
        try {
            (new PublishPublication($publication->id))->handle(app(BlogPublisher::class));
            $this->fail('Storage failure must propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('outcome storage failed', $exception->getMessage());
        } finally {
            PublicationAttempt::flushEventListeners();
        }
        $this->assertDatabaseHas('publications', ['id' => $publication->id, 'status' => 'processing']);
        $this->assertSame('sending', PublicationAttempt::sole()->status);
        try {
            app(BlogPublisher::class)->publish($publication, $payload, PublicationAttempt::sole()->id);
            $this->fail('Unresolved request must not be resent');
        } catch (RuntimeException $exception) {
            $this->assertSame('Execution attempt is not eligible for this payload.', $exception->getMessage());
        }
        (new PublishPublication($publication->id))->handle(app(BlogPublisher::class));
        Http::assertSentCount(1);
    }

    public function test_attempt_records_claim_identity_before_provider_and_success_once(): void
    {
        [$publication, $version, $review] = $this->approved();
        // Existing legacy counters must not produce invented historical attempts.
        $publication->update(['attempts' => 4]);
        $publisher = Mockery::mock(BlogPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturnUsing(function () use ($publication, $version, $review, $publisher) {
            $this->assertDatabaseHas('publication_attempts', [
                'publication_id' => $publication->id, 'content_item_id' => $publication->content_item_id,
                'content_version_id' => $version, 'attempt_number' => 5,
                'provenance' => 'publication_review:'.$review, 'status' => 'started', 'finished_at' => null,
            ]);
            (new PublishPublication($publication->id))->handle($publisher);

            return ['id' => 42, 'url' => 'https://blog.test/post'];
        });
        (new PublishPublication($publication->id))->handle($publisher);
        (new PublishPublication($publication->id))->handle($publisher);
        $this->assertDatabaseCount('publication_attempts', 1);
        $attempt = PublicationAttempt::sole();
        $this->assertSame('published', $attempt->status);
        $this->assertSame('42', $attempt->external_id);
        $this->assertNotNull($attempt->finished_at);
        $this->assertNull($attempt->error_code);
    }

    public function test_provider_exception_records_unknown_outcome_without_raw_diagnostics_or_retry(): void
    {
        [$publication] = $this->approved();
        $publisher = Mockery::mock(BlogPublisher::class);
        $publisher->shouldReceive('publish')->once()->andThrow(new RuntimeException('sensitive-provider-body'));
        try {
            (new PublishPublication($publication->id))->handle($publisher);
            $this->fail('Provider exception must propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('sensitive-provider-body', $exception->getMessage());
        }
        (new PublishPublication($publication->id))->handle($publisher);
        $this->assertDatabaseCount('publication_attempts', 1);
        $this->assertDatabaseHas('publication_attempts', ['status' => 'unknown', 'error_code' => 'publication_outcome_unknown', 'external_id' => null]);
        $this->assertNotNull(PublicationAttempt::sole()->finished_at);
        $this->assertSame('publication_outcome_unknown', $publication->fresh()->last_error);
    }

    public function test_invalid_success_responses_remain_unknown_and_are_never_resent(): void
    {
        config(['services.moonshiner_blog.url' => 'https://blog.test/api', 'services.moonshiner_blog.token' => 'test-only']);
        $responses = [
            ['id' => '', 'url' => 'https://blog.test/post'],
            ['id' => '   ', 'url' => 'https://blog.test/post'],
            ['id' => false, 'url' => 'https://blog.test/post'],
            ['id' => ['unexpected'], 'url' => 'https://blog.test/post'],
            ['id' => 1.5, 'url' => 'https://blog.test/post'],
            ['id' => 42, 'url' => ''],
            ['id' => 42, 'url' => '/relative/post'],
            ['id' => 42, 'url' => 'javascript:alert(1)'],
            ['id' => 42, 'url' => ['unexpected']],
            ['id' => 42],
            [],
        ];
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->push($response);
        }
        Http::fake(['*' => $sequence]);

        foreach ($responses as $response) {
            [$publication] = $this->approved();
            try {
                (new PublishPublication($publication->id))->handle(app(BlogPublisher::class));
                $this->fail('Malformed provider success must not be published');
            } catch (RuntimeException $exception) {
                $this->assertSame('Die Moonshiner-Blog-API lieferte keine gültige ID oder HTTP(S)-URL zurück.', $exception->getMessage());
            }
            (new PublishPublication($publication->id))->handle(app(BlogPublisher::class));
            $this->assertDatabaseHas('publications', ['id' => $publication->id, 'status' => 'failed', 'external_id' => null, 'external_url' => null]);
            $this->assertDatabaseHas('publication_attempts', ['publication_id' => $publication->id, 'status' => 'unknown', 'error_code' => 'publication_outcome_unknown', 'external_id' => null]);
        }
        Http::assertSentCount(count($responses));
        $this->assertDatabaseCount('publication_attempts', count($responses));
    }

    public function test_attempt_insert_failure_rolls_back_claim_without_provider_call(): void
    {
        [$publication] = $this->approved();
        $before = $publication->fresh()->getRawOriginal();
        $publisher = Mockery::mock(BlogPublisher::class);
        $publisher->shouldNotReceive('publish');
        PublicationAttempt::creating(fn () => throw new RuntimeException('attempt insertion failed'));
        try {
            (new PublishPublication($publication->id))->handle($publisher);
            $this->fail('Attempt failure must propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('attempt insertion failed', $exception->getMessage());
        } finally {
            PublicationAttempt::flushEventListeners();
        }
        $this->assertSame($before, $publication->fresh()->getRawOriginal());
        $this->assertDatabaseCount('publication_attempts', 0);
    }

    public function test_outcome_storage_failure_preserves_unresolved_claim_and_never_resends(): void
    {
        [$publication] = $this->approved();
        $publisher = Mockery::mock(BlogPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturn(['id' => 42, 'url' => 'https://blog.test/post']);
        PublicationAttempt::updating(fn () => throw new RuntimeException('outcome storage failed'));
        try {
            (new PublishPublication($publication->id))->handle($publisher);
            $this->fail('Storage failure must propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('outcome storage failed', $exception->getMessage());
        } finally {
            PublicationAttempt::flushEventListeners();
        }
        $this->assertDatabaseHas('publications', ['id' => $publication->id, 'status' => 'processing', 'external_id' => null]);
        $this->assertDatabaseHas('publication_attempts', ['publication_id' => $publication->id, 'status' => 'started', 'finished_at' => null]);
        (new PublishPublication($publication->id))->handle($publisher);
        $this->assertDatabaseCount('publication_attempts', 1);
    }
}
