<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\Pipeline\PipelineResource;
use App\Filament\Widgets\EditorialOverview;
use App\Jobs\PublishPublication;
use App\Models\ContentItem;
use App\Models\Opportunity;
use App\Models\Publication;
use App\Models\User;
use App\Services\Content\CreateContentVersion;
use App\Services\Content\PublicationReviewFingerprint;
use App\Services\Content\RecordPublicationReview;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ContentItemTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        // In-memory DB and real commit boundaries: rebuild without outer transaction.
        RefreshDatabaseState::$migrated = false;
    }

    public function test_deleting_linked_content_preserves_opportunity_and_other_content(): void
    {
        $item = ContentItem::factory()->create();
        $other = ContentItem::factory()->create();
        $opportunity = Opportunity::create(['title' => 'Source topic', 'provenance' => 'test']);
        $item->opportunities()->attach($opportunity);
        $other->opportunities()->attach($opportunity);

        $item->delete();

        $this->assertDatabaseMissing('content_items', ['id' => $item->id]);
        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseMissing('content_item_opportunity', ['content_item_id' => $item->id]);
        $this->assertDatabaseHas('content_item_opportunity', ['content_item_id' => $other->id]);
    }

    public function test_blocked_deletion_rolls_back_opportunity_detach(): void
    {
        $item = ContentItem::factory()->create();
        $opportunity = Opportunity::create(['title' => 'Source topic', 'provenance' => 'test']);
        $item->opportunities()->attach($opportunity);
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');

        try {
            $item->delete();
            $this->fail('Version history must prevent hard deletion.');
        } catch (QueryException $exception) {
            $this->assertDatabaseHas('content_items', ['id' => $item->id]);
            $this->assertDatabaseHas('content_item_opportunity', ['content_item_id' => $item->id]);
        }
    }

    public function test_content_item_defaults_to_draft_and_belongs_to_submitter(): void
    {
        $nick = User::factory()->create();

        $item = ContentItem::create([
            'title' => 'Neue Produktstory',
            'image_path' => null,
            'body' => 'Der erste Textentwurf.',
            'submitted_by' => $nick->id,
        ]);

        $this->assertSame(ContentStatus::Draft, $item->status);
        $this->assertSame(ContentType::Editorial, $item->content_type);
        $this->assertTrue($item->submitter->is($nick));
    }

    public function test_content_item_stores_channels_and_schedule(): void
    {
        $item = ContentItem::factory()->create([
            'status' => ContentStatus::Scheduled,
            'channels' => ['LinkedIn', 'Instagram'],
            'scheduled_at' => '2026-08-01 09:30:00',
        ]);

        $this->assertSame(['LinkedIn', 'Instagram'], $item->channels);
        $this->assertSame(ContentStatus::Scheduled, $item->status);
        $this->assertSame('2026-08-01 09:30', $item->scheduled_at->format('Y-m-d H:i'));
    }

    public function test_content_item_stores_a_specific_content_type(): void
    {
        $item = ContentItem::factory()->create([
            'content_type' => ContentType::Fact,
        ]);

        $this->assertSame(ContentType::Fact, $item->content_type);
        $this->assertDatabaseHas('content_items', [
            'id' => $item->id,
            'content_type' => 'fact',
        ]);
    }

    public function test_user_role_distinguishes_curator_from_contributor(): void
    {
        $max = User::factory()->create(['role' => UserRole::Curator]);
        $nick = User::factory()->create(['role' => UserRole::Contributor]);

        $this->assertTrue($max->isCurator());
        $this->assertFalse($nick->isCurator());
    }

    public function test_contributor_sees_only_their_own_content_while_curator_sees_everything(): void
    {
        $max = User::factory()->create(['role' => UserRole::Curator]);
        $nick = User::factory()->create(['role' => UserRole::Contributor]);
        $otherContributor = User::factory()->create(['role' => UserRole::Contributor]);

        $nicksItem = ContentItem::factory()->create(['submitted_by' => $nick]);
        $otherItem = ContentItem::factory()->create(['submitted_by' => $otherContributor]);

        $this->actingAs($nick);
        $this->assertEquals([$nicksItem->id], ContentItemResource::getEloquentQuery()->pluck('id')->all());

        $this->actingAs($max);
        $this->assertEqualsCanonicalizing(
            [$nicksItem->id, $otherItem->id],
            ContentItemResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_pipeline_contains_only_final_content_with_a_schedule(): void
    {
        $max = User::factory()->create(['role' => UserRole::Curator]);
        $this->actingAs($max);

        $planned = ContentItem::factory()->create([
            'status' => ContentStatus::Approved,
            'scheduled_at' => now()->addDay(),
        ]);
        ContentItem::factory()->create([
            'status' => ContentStatus::Draft,
            'scheduled_at' => now()->addDays(2),
        ]);
        ContentItem::factory()->create([
            'status' => ContentStatus::Approved,
            'scheduled_at' => null,
        ]);

        $this->assertEquals([$planned->id], PipelineResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_curator_dashboard_renders_editorial_overview(): void
    {
        $max = User::factory()->create(['role' => UserRole::Curator]);

        $this->actingAs($max);

        Livewire::test(EditorialOverview::class)
            ->assertSee('Content gesamt')
            ->assertSee('Warten auf dein Review')
            ->assertSee('In der Pipeline');
    }

    public function test_content_has_one_idempotent_publication_per_channel(): void
    {
        $item = ContentItem::factory()->create();

        $publication = $item->publications()->create([
            'channel' => 'linkedin',
            'scheduled_at' => '2026-08-01 09:30:00',
        ]);

        $this->assertSame(PublicationStatus::Pending, $publication->status);
        $this->assertSame(0, $publication->attempts);
        $this->assertTrue($publication->contentItem->is($item));

        $this->expectException(UniqueConstraintViolationException::class);
        Publication::create([
            'content_item_id' => $item->id,
            'channel' => 'linkedin',
        ]);
    }

    public function test_blog_publication_is_sent_and_marked_as_published(): void
    {
        config()->set('services.moonshiner_blog', ['url' => 'https://blog.test/api/content-hub', 'token' => 'secret']);
        Http::fake([
            'https://blog.test/api/content-hub/posts' => Http::response([
                'id' => 42,
                'url' => 'https://moonshiner.example/blog/neue-produktstory',
            ]),
        ]);

        $publication = ContentItem::factory()->create([
            'title' => 'Neue Produktstory',
            'image_path' => null,
        ])->publications()->create(['channel' => 'blog']);

        $item = $publication->contentItem;
        $version = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        DB::table('publications')->where('id', $publication->id)->update(['content_version_id' => $version]);
        $curator = User::factory()->create(['role' => UserRole::Curator]);
        app(RecordPublicationReview::class)->approve(
            $publication->id, $curator->id,
            app(PublicationReviewFingerprint::class)->forVerifiedPublication($publication->id),
        );

        app()->call([new PublishPublication($publication->id), 'handle']);

        $publication->refresh();
        $this->assertSame(PublicationStatus::Published, $publication->status);
        $this->assertSame('42', $publication->external_id);
        $this->assertSame('https://moonshiner.example/blog/neue-produktstory', $publication->external_url);
        $this->assertSame(1, $publication->attempts);
        Http::assertSent(fn ($request) => $request->header('X-Content-Hub-Token')[0] === 'secret');
    }

    public function test_ambiguous_blog_failure_is_not_retried_by_http_or_queue(): void
    {
        config()->set('services.moonshiner_blog', ['url' => 'https://blog.test/api/content-hub', 'token' => 'test-only']);
        Http::fakeSequence()
            ->push(['error' => 'Response lost after accepting post'], 500)
            ->push(['id' => 42, 'url' => 'https://blog.test/duplicate']);

        $item = ContentItem::factory()->create(['image_path' => null]);
        $publication = $item->publications()->create(['channel' => 'blog']);
        $version = app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        DB::table('publications')->where('id', $publication->id)->update(['content_version_id' => $version]);
        $curator = User::factory()->create(['role' => UserRole::Curator]);
        app(RecordPublicationReview::class)->approve(
            $publication->id, $curator->id,
            app(PublicationReviewFingerprint::class)->forVerifiedPublication($publication->id),
        );

        try {
            app()->call([new PublishPublication($publication->id), 'handle']);
            $this->fail('Ambiguous provider failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Blog transport outcome is unknown.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $publication->refresh();
        $this->assertSame(PublicationStatus::Failed, $publication->status);
        $this->assertSame(1, $publication->attempts);
        $this->assertNull($publication->external_id);
        $before = $publication->getRawOriginal();
        app()->call([new PublishPublication($publication->id), 'handle']);
        $this->assertSame($before, $publication->fresh()->getRawOriginal());
        Http::assertSentCount(1);
    }

    public function test_approving_content_creates_and_updates_channel_publications(): void
    {
        $item = ContentItem::factory()->create([
            'status' => ContentStatus::Approved,
            'channels' => ['Blog', 'LinkedIn'],
            'scheduled_at' => '2026-08-01 09:30:00',
        ]);

        $this->assertDatabaseHas('publications', ['content_item_id' => $item->id, 'channel' => 'blog']);
        $this->assertDatabaseHas('publications', ['content_item_id' => $item->id, 'channel' => 'linkedin']);

        $item->update([
            'channels' => ['Blog'],
            'scheduled_at' => '2026-08-02 10:00:00',
        ]);

        $this->assertDatabaseMissing('publications', ['content_item_id' => $item->id, 'channel' => 'linkedin']);
        $this->assertSame(
            '2026-08-02 10:00',
            $item->publications()->where('channel', 'blog')->firstOrFail()->scheduled_at->format('Y-m-d H:i'),
        );
    }

    public function test_non_final_content_has_no_pending_publications(): void
    {
        $item = ContentItem::factory()->create([
            'status' => ContentStatus::Approved,
            'channels' => ['Blog'],
        ]);

        $item->update(['status' => ContentStatus::InReview]);

        $this->assertDatabaseMissing('publications', [
            'content_item_id' => $item->id,
            'status' => PublicationStatus::Pending->value,
        ]);
    }
}
