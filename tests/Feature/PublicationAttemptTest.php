<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Publication;
use App\Models\PublicationAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicationAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(): PublicationAttempt
    {
        $piece = ContentItem::factory()->create();
        $publication = Publication::create(['content_item_id' => $piece->id, 'channel' => 'blog']);
        (require database_path('migrations/2026_10_15_140100_backfill_legacy_content_versions.php'))->up();

        return PublicationAttempt::create([
            'publication_id' => $publication->id, 'content_item_id' => $piece->id,
            'content_version_id' => DB::table('content_versions')->where('content_item_id', $piece->id)->value('id'),
            'attempt_number' => 1, 'provenance' => 'test', 'started_at' => now(),
        ]);
    }

    public function test_attempt_history_is_separate_from_publication_state_and_has_no_side_effects(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $attempt = $this->attempt();
        $before = DB::table('publications')->get()->toJson();
        $retry = $attempt->replicate();
        $retry->attempt_number = 2;
        $retry->save();
        $attempt->status = 'failed';
        $attempt->error_code = 'provider_timeout';
        $attempt->finished_at = now();
        $attempt->save();
        $this->assertCount(2, $attempt->publication->executionAttempts);
        $this->assertSame('started', $retry->fresh()->status);
        $this->assertNotNull($attempt->fresh()->finished_at);
        $this->assertSame($before, DB::table('publications')->get()->toJson());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_duplicate_attempt_number_is_rejected(): void
    {
        $attempt = $this->attempt();
        $this->expectException(QueryException::class);
        $attempt->replicate()->save();
    }

    public function test_attempt_cannot_reference_another_piece_version(): void
    {
        $attempt = $this->attempt();
        $other = $this->attempt();
        $this->expectException(QueryException::class);
        $attempt->content_version_id = $other->content_version_id;
        $attempt->save();
    }

    public function test_attempt_cannot_reference_another_piece_publication(): void
    {
        $attempt = $this->attempt();
        $other = $this->attempt();
        $this->expectException(QueryException::class);
        $attempt->publication_id = $other->publication_id;
        $attempt->attempt_number = 2;
        $attempt->save();
    }

    public function test_publication_with_execution_history_cannot_be_deleted(): void
    {
        $attempt = $this->attempt();
        $this->expectException(QueryException::class);
        $attempt->publication->delete();
    }

    public function test_rollback_does_not_modify_legacy_publications(): void
    {
        $this->attempt();
        $before = DB::table('publications')->get()->toJson();
        $migration = require database_path('migrations/2026_10_15_153000_create_publication_attempts_table.php');
        $migration->down();
        $this->assertSame($before, DB::table('publications')->get()->toJson());
        $migration->up();
        $this->assertDatabaseCount('publication_attempts', 0);
    }
}
