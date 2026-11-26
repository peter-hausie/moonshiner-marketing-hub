<?php

namespace Tests\Feature;

use App\Jobs\PublishPublication;
use App\Models\ContentItem;
use App\Services\Publishing\BlogPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class StagingPublishingIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'staging');
        config(['services.moonshiner_blog.url' => 'https://publisher.test/api', 'services.moonshiner_blog.token' => 'test-only']);
        Http::fake(['*' => Http::response(['id' => 42, 'url' => 'https://publisher.test/post'])]);
    }

    public function test_staging_dispatch_does_not_enqueue_or_change_due_publications(): void
    {
        Queue::fake();
        $publication = ContentItem::factory()->create()->publications()->create(['channel' => 'blog']);
        $before = $publication->fresh()->getRawOriginal();

        $this->artisan('publications:dispatch')->expectsOutput('Publishing is disabled in staging.')->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame($before, $publication->fresh()->getRawOriginal());
    }

    public function test_already_queued_staging_job_preserves_state_and_never_calls_publisher(): void
    {
        $publication = ContentItem::factory()->create()->publications()->create([
            'channel' => 'blog', 'attempts' => 2, 'last_error' => 'previous failure',
        ]);
        $before = $publication->fresh()->getRawOriginal();
        $publisher = $this->mock(BlogPublisher::class);
        $publisher->shouldNotReceive('publish');

        (new PublishPublication($publication->id))->handle($publisher);

        $this->assertSame($before, $publication->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_direct_staging_publisher_call_fails_before_http_or_state_changes(): void
    {
        $publication = ContentItem::factory()->create()->publications()->create(['channel' => 'blog']);
        $before = $publication->fresh()->getRawOriginal();

        try {
            app(BlogPublisher::class)->publish($publication);
            $this->fail('Staging publishing must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Publishing is disabled in staging.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame($before, $publication->fresh()->getRawOriginal());
    }

    public function test_non_staging_dispatch_rejects_unapproved_due_publications(): void
    {
        $this->app->instance('env', 'testing');
        Queue::fake();
        $due = ContentItem::factory()->create()->publications()->create(['channel' => 'blog']);
        ContentItem::factory()->create()->publications()->create(['channel' => 'blog', 'scheduled_at' => now()->addDay()]);

        $this->artisan('publications:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
