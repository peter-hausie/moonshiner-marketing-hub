<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Opportunity;
use App\Models\ResearchItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchOpportunityTest extends TestCase
{
    use RefreshDatabase;

    private function research(): ResearchItem
    {
        return ResearchItem::create([
            'title' => 'Source observation',
            'source_url' => 'https://example.invalid/article',
            'observed_at' => '2026-10-15 12:00:00',
            'provenance' => 'manual_research',
        ]);
    }

    private function opportunity(): Opportunity
    {
        return Opportunity::create(['title' => 'Editorial proposal', 'provenance' => 'human']);
    }

    public function test_many_to_many_evidence_and_piece_links_do_not_publish_or_change_legacy_rows(): void
    {
        $piece = ContentItem::factory()->create();
        $before = DB::table('content_items')->get()->toJson();
        Bus::fake();
        Http::preventStrayRequests();
        $source = $this->research();
        $secondSource = $this->research();
        $opportunity = $this->opportunity();
        $secondOpportunity = $this->opportunity();
        $opportunity->researchItems()->attach([$source->id, $secondSource->id]);
        $secondOpportunity->researchItems()->attach($source);
        $opportunity->contentItems()->attach($piece);
        $secondOpportunity->contentItems()->attach($piece);

        $this->assertCount(2, $source->opportunities);
        $this->assertCount(2, $opportunity->researchItems);
        $this->assertCount(2, $piece->opportunities);
        $this->assertTrue($opportunity->contentItems->first()->is($piece));
        $this->assertNull($source->fresh()->coverage);
        $this->assertNull($source->created_by);
        $this->assertSame('proposed', $opportunity->fresh()->status);
        $this->assertSame('2026-10-15 12:00:00', $source->observed_at->format('Y-m-d H:i:s'));
        $source->update(['coverage' => ['state' => 'partial', 'reason' => 'source unavailable']]);
        $this->assertSame('partial', $source->fresh()->coverage['state']);
        $this->assertSame($before, DB::table('content_items')->get()->toJson());
        $this->assertDatabaseCount('publications', 0);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_duplicate_evidence_link_is_rejected(): void
    {
        $source = $this->research();
        $opportunity = $this->opportunity();
        $opportunity->researchItems()->attach($source);
        $this->expectException(QueryException::class);
        $opportunity->researchItems()->attach($source);
    }

    public function test_linked_evidence_cannot_be_deleted_accidentally(): void
    {
        $source = $this->research();
        $this->opportunity()->researchItems()->attach($source);
        $this->expectException(QueryException::class);
        $source->delete();
    }

    public function test_dangling_piece_link_is_rejected(): void
    {
        $opportunity = $this->opportunity();
        $this->expectException(QueryException::class);
        $opportunity->contentItems()->attach(999999);
    }

    public function test_additive_schema_rollback_and_reapply_preserve_existing_content_and_rights(): void
    {
        ContentItem::factory()->create();
        $tables = ['users', 'content_items', 'content_versions', 'publications'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()->toJson(),
        ])->all();
        $before = $snapshot();
        $migration = require database_path('migrations/2026_10_15_150000_create_research_and_opportunities_tables.php');
        $migration->down();
        $this->assertSame($before, $snapshot());
        $migration->up();
        $this->assertSame($before, $snapshot());
        $this->assertDatabaseCount('research_items', 0);
        $this->assertDatabaseCount('opportunities', 0);
    }
}
