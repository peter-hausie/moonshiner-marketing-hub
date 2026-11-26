<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Services\Agents\DraftContext;
use App\Services\Agents\DraftSubmission;
use App\Services\Content\CreateContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DraftContextTest extends TestCase
{
    use RefreshDatabase;

    private function versionedItem(): ContentItem
    {
        $item = ContentItem::factory()->create(['body' => "Exact\n text ", 'context' => null]);
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');

        return $item;
    }

    private function submission(DraftContext $context): DraftSubmission
    {
        return DraftSubmission::fromInput(array_intersect_key($context->data, array_flip([
            'content_item_id', 'expected_version', 'base_sha256',
        ])) + ['title' => 'New title', 'body' => 'New body', 'job_id' => 'job:1',
            'idempotency_key' => 'result:1'], 'underwood', ['draft:write']);
    }

    public function test_context_reads_persisted_state_without_writes_or_private_path_disclosure(): void
    {
        $item = $this->versionedItem();
        $before = DB::table('content_items')->get()->toJson();
        Bus::fake();
        Http::preventStrayRequests();
        $item->body = 'Not saved';
        $context = DraftContext::forContentItem($item->id);
        $this->assertSame("Exact\n text ", $context->data['body']);
        $this->assertNull($context->data['context']);
        $this->assertSame(1, $context->data['expected_version']);
        $this->assertArrayNotHasKey('image_path', $context->data);
        $this->assertArrayNotHasKey('review_notes', $context->data);
        $this->assertSame($context->data, DraftContext::forContentItem($item->id)->data);
        $this->assertTrue($context->matches($this->submission($context)));
        $this->assertSame($before, DB::table('content_items')->get()->toJson());
        $this->assertDatabaseCount('content_versions', 1);
        $this->assertDatabaseCount('publications', 0);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_unsnapshotted_edits_invalidate_context_even_without_timestamp_change(): void
    {
        $item = $this->versionedItem();
        $context = DraftContext::forContentItem($item->id);
        $submission = $this->submission($context);
        $original = (array) DB::table('content_items')->find($item->id);
        foreach (['title' => 'Changed', 'body' => 'Changed', 'context' => '',
            'content_type' => 'changed', 'image_path' => 'private/new.png',
            'status' => 'approved', 'channels' => '[]', 'scheduled_at' => '2026-10-18 12:00:00',
            'review_notes' => 'Changed', 'reviewed_by' => $item->submitted_by] as $field => $value) {
            DB::table('content_items')->where('id', $item->id)->update([$field => $value]);
            $fresh = DraftContext::forContentItem($item->id);
            $this->assertSame(1, $fresh->data['expected_version']);
            $this->assertFalse($fresh->matches($submission), $field);
            DB::table('content_items')->where('id', $item->id)->update([$field => $original[$field]]);
        }
        $this->assertTrue(DraftContext::forContentItem($item->id)->matches($submission));
    }

    public function test_new_version_and_other_piece_cannot_match_original_context(): void
    {
        $item = $this->versionedItem();
        $context = DraftContext::forContentItem($item->id);
        $submission = $this->submission($context);
        app(CreateContentVersion::class)->create($item->id, $item->submitted_by, 'human_snapshot');
        $fresh = DraftContext::forContentItem($item->id);
        $this->assertSame($context->data['base_sha256'], $fresh->data['base_sha256']);
        $this->assertFalse($fresh->matches($submission));
        $other = $this->versionedItem();
        $this->assertFalse(DraftContext::forContentItem($other->id)->matches($submission));
    }

    public function test_unversioned_piece_requires_explicit_baseline_without_creating_one(): void
    {
        $item = ContentItem::factory()->create();
        try {
            DraftContext::forContentItem($item->id);
            $this->fail('Unversioned context admitted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Draft context requires an existing version.', $error->getMessage());
            $this->assertDatabaseCount('content_versions', 0);
        }
    }
}
