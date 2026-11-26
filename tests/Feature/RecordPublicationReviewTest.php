<?php

namespace Tests\Feature;

use App\Jobs\PublishPublication;
use App\Models\ContentItem;
use App\Models\Publication;
use App\Models\User;
use App\Services\Content\ChangePublicationBinding;
use App\Services\Content\CreateContentVersion;
use App\Services\Content\CurrentPublicationApproval;
use App\Services\Content\PublicationReviewFingerprint;
use App\Services\Content\RecordPublicationReview;
use App\Services\Publishing\BlogPublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RecordPublicationReviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $item = ContentItem::factory()->create(['image_path' => null]);
        $version = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $publication = DB::table('publications')->insertGetId([
            'content_item_id' => $item->id, 'content_version_id' => $version, 'channel' => 'blog',
        ]);
        $curator = User::factory()->create(['role' => 'curator']);
        $hash = app(PublicationReviewFingerprint::class)->forVerifiedPublication($publication);

        return [$publication, $curator->id, $hash, $version];
    }

    public function test_dispatch_admits_only_current_approved_due_pending_publications(): void
    {
        Bus::fake();
        Http::fake();
        $allowed = [];
        foreach (['approved', 'unscheduled', 'absent', 'revoked', 'stale', 'invalid_media', 'rebound', 'future', 'published', 'failed', 'processing'] as $state) {
            [$publication, $curator, $hash, $version] = $this->fixture();
            DB::table('publications')->where('id', $publication)->update(['scheduled_at' => now()->subMinute()]);
            if ($state !== 'absent') {
                app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
            }
            if ($state === 'revoked') {
                app(RecordPublicationReview::class)->revoke($publication, $curator);
            } elseif ($state === 'stale') {
                DB::table('content_versions')->where('id', $version)->update(['title' => 'Changed after review']);
            } elseif ($state === 'invalid_media') {
                DB::table('content_versions')->where('id', $version)->update(['media_snapshot' => 'null']);
            } elseif ($state === 'rebound') {
                DB::table('publications')->where('id', $publication)->update(['channel' => 'linkedin']);
            } elseif ($state === 'future') {
                DB::table('publications')->where('id', $publication)->update(['scheduled_at' => now()->addDay()]);
            } elseif ($state === 'unscheduled') {
                DB::table('publications')->where('id', $publication)->update(['scheduled_at' => null]);
            } elseif (in_array($state, ['published', 'failed', 'processing'], true)) {
                DB::table('publications')->where('id', $publication)->update(['status' => $state]);
            }
            if (in_array($state, ['approved', 'unscheduled'], true)) {
                $allowed[] = $publication;
            }
        }
        $before = DB::table('publications')->orderBy('id')->get()->toJson();
        $reviews = DB::table('publication_reviews')->orderBy('id')->get()->toJson();

        $this->artisan('publications:dispatch')->assertSuccessful();

        Bus::assertDispatchedTimes(PublishPublication::class, 2);
        foreach ($allowed as $id) {
            Bus::assertDispatched(PublishPublication::class, fn ($job) => $job->publicationId === $id);
        }
        $this->assertSame($before, DB::table('publications')->orderBy('id')->get()->toJson());
        $this->assertSame($reviews, DB::table('publication_reviews')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_queued_worker_rechecks_approval_and_eligibility_without_mutating_rejected_jobs(): void
    {
        Http::fake();
        $publisher = $this->mock(BlogPublisher::class);
        $publisher->shouldNotReceive('publish');
        foreach (['absent', 'revoked', 'stale', 'invalid_media', 'rebound', 'future', 'published', 'failed', 'processing'] as $state) {
            [$publication, $curator, $hash, $version] = $this->fixture();
            $job = new PublishPublication($publication);
            if ($state !== 'absent') {
                app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
            }
            if ($state === 'revoked') {
                app(RecordPublicationReview::class)->revoke($publication, $curator);
            } elseif ($state === 'stale') {
                DB::table('content_versions')->where('id', $version)->update(['title' => 'Changed after enqueue']);
            } elseif ($state === 'invalid_media') {
                DB::table('content_versions')->where('id', $version)->update(['media_snapshot' => 'null']);
            } elseif ($state === 'rebound') {
                DB::table('publications')->where('id', $publication)->update(['channel' => 'linkedin']);
            } elseif ($state === 'future') {
                DB::table('publications')->where('id', $publication)->update(['scheduled_at' => now()->addDay()]);
            } else {
                if (in_array($state, ['published', 'failed', 'processing'], true)) {
                    DB::table('publications')->where('id', $publication)->update(['status' => $state]);
                }
            }
            $before = DB::table('publications')->orderBy('id')->get()->toJson();
            $reviews = DB::table('publication_reviews')->orderBy('id')->get()->toJson();
            $job->handle($publisher);
            $this->assertSame($before, DB::table('publications')->orderBy('id')->get()->toJson(), $state);
            $this->assertSame($reviews, DB::table('publication_reviews')->orderBy('id')->get()->toJson(), $state);
        }
        Http::assertNothingSent();
    }

    public function test_approved_worker_reaches_publisher_once_and_duplicate_completed_job_is_ignored(): void
    {
        [$publication, $curator, $hash] = $this->fixture();
        app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        Http::fake();
        $publisher = $this->mock(BlogPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturn(['id' => 'test-post', 'url' => 'https://publisher.test/post']);
        $job = new PublishPublication($publication);
        $job->handle($publisher);
        $job->handle($publisher);
        $this->assertDatabaseHas('publications', ['id' => $publication, 'status' => 'published', 'attempts' => 1, 'external_id' => 'test-post']);
        Http::assertNothingSent();
    }

    public function test_claim_is_complete_before_provider_and_inflight_duplicate_is_ignored(): void
    {
        [$publication, $curator, $hash] = $this->fixture();
        app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        $level = DB::transactionLevel();
        $publisher = $this->mock(BlogPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturnUsing(function ($claimed) use ($publication, $publisher, $level) {
            $this->assertSame($level, DB::transactionLevel());
            $this->assertDatabaseHas('publications', ['id' => $publication, 'status' => 'processing', 'attempts' => 1]);
            (new PublishPublication($publication))->handle($publisher);

            return ['id' => 'test-post', 'url' => 'https://publisher.test/post'];
        });
        (new PublishPublication($publication))->handle($publisher);
        $this->assertDatabaseHas('publications', ['id' => $publication, 'status' => 'published', 'attempts' => 1]);
    }

    public function test_failed_claim_rolls_back_without_calling_provider(): void
    {
        [$publication, $curator, $hash] = $this->fixture();
        app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        $before = DB::table('publications')->where('id', $publication)->first();
        $publisher = $this->mock(BlogPublisher::class);
        $publisher->shouldNotReceive('publish');
        $event = 'eloquent.updated: '.Publication::class;
        Event::listen($event, function () {
            throw new RuntimeException('Injected claim persistence failure');
        });
        try {
            (new PublishPublication($publication))->handle($publisher);
            $this->fail('Claim failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected claim persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertEquals($before, DB::table('publications')->where('id', $publication)->first());
    }

    public function test_approval_revocation_and_reapproval_append_history_without_publishing(): void
    {
        [$publication, $curator, $hash] = $this->fixture();
        Bus::fake();
        Http::fake();
        $before = DB::table('publications')->get()->toJson();
        $service = app(RecordPublicationReview::class);
        $first = $service->approve($publication, $curator, $hash);
        $this->assertSame($first, $service->approve($publication, $curator, $hash));
        $original = DB::table('publication_reviews')->where('id', $first)->first();
        $revoked = $service->revoke($publication, $curator);
        $this->assertSame($revoked, $service->revoke($publication, $curator));
        $this->assertGreaterThan($revoked, $service->approve($publication, $curator, $hash));
        $this->assertEquals($original, DB::table('publication_reviews')->where('id', $first)->first());
        $this->assertSame(['approved', 'revoked', 'approved'], DB::table('publication_reviews')->orderBy('id')->pluck('decision')->all());
        $this->assertSame($before, DB::table('publications')->get()->toJson());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_current_approval_uses_latest_decision_without_writes_or_dispatch(): void
    {
        [$publication, $curator, $hash] = $this->fixture();
        $writer = app(RecordPublicationReview::class);
        $reader = app(CurrentPublicationApproval::class);
        Bus::fake();
        Http::fake();
        foreach (['absent', 'approved', 'revoked', 'reapproved'] as $state) {
            $expected = match ($state) {
                'approved', 'reapproved' => $writer->approve($publication, $curator, $hash),
                'revoked' => $writer->revoke($publication, $curator),
                default => null,
            };
            $before = DB::table('publication_reviews')->get()->toJson();
            try {
                $actual = $reader->requireCurrent($publication);
                $this->assertContains($state, ['approved', 'reapproved']);
                $this->assertSame($expected, $actual);
            } catch (RuntimeException) {
                $this->assertContains($state, ['absent', 'revoked']);
            }
            $this->assertSame($before, DB::table('publication_reviews')->get()->toJson());
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_current_approval_rejects_changed_binding_text_and_missing_media(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        $original = DB::table('content_versions')->where('id', $version)->first();
        $next = app(CreateContentVersion::class)->create($original->content_item_id, $original->created_by, 'human_snapshot');
        $digest = hash('sha256', 'missing');
        foreach ([
            ['publications', $publication, ['channel' => 'linkedin']],
            ['publications', $publication, ['content_version_id' => $next]],
            ['publications', $publication, ['content_version_id' => null]],
            ['content_versions', $version, ['body' => 'changed']],
            ['content_versions', $version, ['media_snapshot' => json_encode([
                'disk' => 'content_snapshots', 'path' => $digest, 'sha256' => $digest, 'bytes' => 7,
            ])]],
        ] as [$table, $id, $changes]) {
            DB::table($table)->where('id', $id)->update($changes);
            try {
                app(CurrentPublicationApproval::class)->requireCurrent($publication);
                $this->fail('Stale or invalid approval accepted');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('publication_reviews', 1);
            }
            DB::table('publications')->where('id', $publication)->update(['channel' => 'blog', 'content_version_id' => $version]);
            DB::table('content_versions')->where('id', $version)->update(['body' => $original->body, 'media_snapshot' => null]);
        }
    }

    public function test_binding_change_permanently_revokes_even_after_return_to_original(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        $approved = app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        $original = DB::table('publication_reviews')->where('id', $approved)->first();
        $item = DB::table('content_versions')->where('id', $version)->first();
        $next = app(CreateContentVersion::class)->create($item->content_item_id, $item->created_by, 'human_snapshot');
        Bus::fake();
        Http::fake();
        $service = app(ChangePublicationBinding::class);
        $service->change($publication, $curator, $next, 'linkedin', $version, 'blog');
        $service->change($publication, $curator, $version, 'blog', $next, 'linkedin');
        $this->assertDatabaseCount('publication_reviews', 2);
        $this->assertEquals($original, DB::table('publication_reviews')->where('id', $approved)->first());
        $this->assertDatabaseHas('publication_reviews', ['decision' => 'revoked', 'fingerprint' => $hash, 'content_version_id' => $version, 'channel' => 'blog']);
        try {
            app(CurrentPublicationApproval::class)->requireCurrent($publication);
            $this->fail('Returning to the original binding revived approval');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('publications', ['id' => $publication, 'status' => 'pending']);
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_noop_binding_preserves_approval_and_invalid_media_does_not_block_revocation(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        $before = DB::table('publications')->get()->toJson();
        $service = app(ChangePublicationBinding::class);
        $service->change($publication, $curator, $version, 'blog', $version, 'blog');
        $this->assertSame($before, DB::table('publications')->get()->toJson());
        $this->assertDatabaseCount('publication_reviews', 1);
        DB::table('content_versions')->where('id', $version)->update(['media_snapshot' => 'null']);
        $service->change($publication, $curator, $version, 'linkedin', $version, 'blog');
        $this->assertDatabaseCount('publication_reviews', 2);
        $this->assertDatabaseHas('publication_reviews', ['decision' => 'revoked', 'fingerprint' => $hash]);
    }

    public function test_unapproved_binding_changes_do_not_invent_review_history(): void
    {
        [$publication, $curator, , $version] = $this->fixture();
        DB::table('publications')->where('id', $publication)->update(['content_version_id' => null]);
        app(ChangePublicationBinding::class)->change($publication, $curator, $version, 'linkedin', null, 'blog');
        $this->assertDatabaseCount('publication_reviews', 0);
        $this->assertDatabaseHas('publications', ['id' => $publication, 'content_version_id' => $version, 'channel' => 'linkedin']);
    }

    public function test_invalid_binding_edits_preserve_publication_and_approval(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        [, , , $foreignVersion] = $this->fixture();
        app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        $service = app(ChangePublicationBinding::class);
        foreach ([
            [$foreignVersion, 'blog', $version, 'blog'],
            [$version, ' ', $version, 'blog'],
            [$version, str_repeat('a', 256), $version, 'blog'],
            [$version, 'linkedin', null, 'blog'],
            [$version, 'linkedin', $version, 'linkedin'],
        ] as [$target, $channel, $expected, $oldChannel]) {
            $before = DB::table('publications')->get()->toJson();
            try {
                $service->change($publication, $curator, $target, $channel, $expected, $oldChannel);
                $this->fail('Invalid edit accepted');
            } catch (RuntimeException|RecordNotFoundException) {
                $this->assertSame($before, DB::table('publications')->get()->toJson());
                $this->assertDatabaseCount('publication_reviews', 1);
            }
        }
        foreach (['processing', 'published', 'failed'] as $status) {
            DB::table('publications')->where('id', $publication)->update(['status' => $status]);
            try {
                $service->change($publication, $curator, $version, 'linkedin', $version, 'blog');
                $this->fail('Nonpending edit accepted');
            } catch (RuntimeException) {
                $this->assertDatabaseHas('publications', ['id' => $publication, 'status' => $status, 'channel' => 'blog']);
                $this->assertDatabaseCount('publication_reviews', 1);
            }
        }
        DB::table('publications')->where('id', $publication)->update(['status' => 'pending']);
        DB::table('users')->where('id', $curator)->update(['role' => 'contributor']);
        $this->expectException(AuthorizationException::class);
        $service->change($publication, $curator, $version, 'linkedin', $version, 'blog');
    }

    public function test_channel_collision_rolls_back_revocation_and_binding(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        $approval = app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        $piece = DB::table('publications')->where('id', $publication)->value('content_item_id');
        DB::table('publications')->insert([
            'content_item_id' => $piece, 'content_version_id' => $version, 'channel' => 'linkedin',
        ]);
        $before = DB::table('publications')->get()->toJson();
        try {
            app(ChangePublicationBinding::class)->change($publication, $curator, $version, 'linkedin', $version, 'blog');
            $this->fail('Duplicate piece/channel accepted');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame($before, DB::table('publications')->get()->toJson());
            $this->assertDatabaseCount('publication_reviews', 1);
            $this->assertSame($approval, app(CurrentPublicationApproval::class)->requireCurrent($publication));
        }
    }

    public function test_enclosing_rollback_restores_binding_and_revocation_together(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
        DB::beginTransaction();
        app(ChangePublicationBinding::class)->change($publication, $curator, $version, 'linkedin', $version, 'blog');
        $this->assertDatabaseCount('publication_reviews', 2);
        DB::rollBack();
        $this->assertDatabaseCount('publication_reviews', 1);
        $this->assertDatabaseHas('publications', ['id' => $publication, 'channel' => 'blog']);
        $this->assertIsInt(app(CurrentPublicationApproval::class)->requireCurrent($publication));
    }

    public function test_current_persisted_role_is_required_for_both_operations(): void
    {
        [$publication, $curator, $hash] = $this->fixture();
        $service = app(RecordPublicationReview::class);
        $service->approve($publication, $curator, $hash);
        DB::table('users')->where('id', $curator)->update(['role' => 'contributor']);
        foreach (['approve', 'revoke'] as $method) {
            try {
                $service->$method($publication, $curator, $hash);
                $this->fail('Demoted curator accepted');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('publication_reviews', 1);
            }
        }
    }

    public function test_stale_text_or_channel_cannot_be_approved(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        $service = app(RecordPublicationReview::class);
        DB::table('publications')->where('id', $publication)->update(['channel' => 'linkedin']);
        try {
            $service->approve($publication, $curator, $hash);
            $this->fail('Changed channel accepted');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('publication_reviews', 0);
        }
        DB::table('publications')->where('id', $publication)->update(['channel' => 'blog']);
        DB::table('content_versions')->where('id', $version)->update(['body' => 'changed']);
        $this->expectException(RuntimeException::class);
        $service->approve($publication, $curator, $hash);
    }

    public function test_revocation_preserves_original_identity_after_rebinding_and_bad_media(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        $service = app(RecordPublicationReview::class);
        $service->approve($publication, $curator, $hash);
        DB::table('content_versions')->where('id', $version)->update(['media_snapshot' => 'null']);
        DB::table('publications')->where('id', $publication)->update(['content_version_id' => null, 'channel' => 'linkedin']);
        $id = $service->revoke($publication, $curator);
        $this->assertDatabaseHas('publication_reviews', [
            'id' => $id, 'decision' => 'revoked', 'fingerprint' => $hash,
            'channel' => 'blog', 'content_version_id' => $version, 'decided_by' => $curator,
        ]);
    }

    public function test_revocation_without_approval_does_not_invent_history(): void
    {
        [$publication, $curator] = $this->fixture();
        try {
            app(RecordPublicationReview::class)->revoke($publication, $curator);
            $this->fail('Revocation without approval accepted');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('publication_reviews', 0);
        }
    }

    public function test_rebinding_requires_a_new_review_even_for_identical_text(): void
    {
        [$publication, $curator, $hash, $version] = $this->fixture();
        $item = DB::table('content_versions')->where('id', $version)->first();
        $next = app(CreateContentVersion::class)->create($item->content_item_id, $item->created_by, 'human_snapshot');
        DB::table('publications')->where('id', $publication)->update(['content_version_id' => $next]);
        try {
            app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
            $this->fail('Stale version accepted');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('publication_reviews', 0);
        }
    }

    public function test_invalid_media_cannot_create_approval_even_with_matching_metadata_hash(): void
    {
        [$publication, $curator, , $version] = $this->fixture();
        $digest = hash('sha256', 'missing');
        DB::table('content_versions')->where('id', $version)->update(['media_snapshot' => json_encode([
            'disk' => 'content_snapshots', 'path' => $digest, 'sha256' => $digest, 'bytes' => 7,
        ])]);
        $hash = app(PublicationReviewFingerprint::class)->forPublication($publication);
        try {
            app(RecordPublicationReview::class)->approve($publication, $curator, $hash);
            $this->fail('Missing bytes approved');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('publication_reviews', 0);
        }
    }
}
