<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentVersionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function schemaMigration(): object
    {
        return require database_path('migrations/2026_10_15_140000_create_content_versions_table.php');
    }

    private function backfillMigration(): object
    {
        return require database_path('migrations/2026_10_15_140100_backfill_legacy_content_versions.php');
    }

    private function legacyRows(): array
    {
        return collect(['users', 'content_items', 'publications'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_populated_legacy_migration_and_rollback_preserve_all_original_rows(): void
    {
        // Recreate the pre-version schema in reverse dependency order.
        $publicationSchema = require database_path('migrations/2026_10_15_142000_add_version_reference_to_publications.php');
        $publicationSchema->down();
        $this->schemaMigration()->down();
        $item = ContentItem::factory()->create([
            'title' => 'Übernahme: unverändert', 'body' => "Markdown\n**Original**",
            'context' => null, 'image_path' => 'images/existing.png',
            'status' => ContentStatus::Approved, 'channels' => ['Blog'],
        ]);
        ContentItem::factory()->create(['image_path' => null]);
        DB::table('publications')->update(['external_id' => 'existing-provider-id', 'attempts' => 2]);
        $before = $this->legacyRows(); // Includes passwords and tokens, compared only in memory.
        Artisan::call('content-hub:inventory');
        $inventory = Artisan::output();
        Bus::fake();
        Http::preventStrayRequests();

        $this->schemaMigration()->up();
        $this->backfillMigration()->up();
        $this->assertSame($before, $this->legacyRows());
        Artisan::call('content-hub:inventory');
        $this->assertSame($inventory, Artisan::output());
        $this->assertDatabaseCount('content_versions', 2);
        $version = DB::table('content_versions')->where('content_item_id', $item->id)->first();
        foreach (['title', 'body', 'context', 'content_type', 'image_path'] as $field) {
            $this->assertSame($item->getRawOriginal($field), $version->$field);
        }
        $this->assertSame($item->submitted_by, $version->created_by);
        $this->assertSame($item->getRawOriginal('updated_at'), $version->created_at);
        $this->assertSame('legacy_baseline', $version->provenance);
        $this->assertSame(1, $version->version_number);
        $this->assertFalse(Schema::hasColumn('content_versions', 'approved_at'));

        $this->backfillMigration()->down();
        $this->schemaMigration()->down();
        $this->assertSame($before, $this->legacyRows());
        $this->schemaMigration()->up();
        $this->backfillMigration()->up();
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertSame($before, $this->legacyRows());
        $publicationSchema->up();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_retry_imports_only_missing_baselines_and_preserves_existing_snapshots(): void
    {
        $original = ContentItem::factory()->create(['body' => 'Original snapshot']);
        $this->backfillMigration()->up();
        $snapshot = DB::table('content_versions')->orderBy('id')->get()->toJson();
        DB::table('content_items')->where('id', $original->id)->update(['body' => 'Later legacy edit']);

        $this->backfillMigration()->up();
        $this->assertSame($snapshot, DB::table('content_versions')->orderBy('id')->get()->toJson());

        $missing = ContentItem::factory()->create(['body' => 'Not yet imported']);
        $this->backfillMigration()->up();
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertDatabaseHas('content_versions', [
            'content_item_id' => $original->id, 'body' => 'Original snapshot',
        ]);
        $this->assertDatabaseHas('content_versions', [
            'content_item_id' => $missing->id, 'body' => 'Not yet imported',
        ]);
    }

    public function test_retry_preserves_non_baseline_version_one_and_later_versions(): void
    {
        $item = ContentItem::factory()->create();
        $this->backfillMigration()->up();
        DB::table('content_versions')->where('content_item_id', $item->id)->update([
            'provenance' => 'human_edit', 'body' => 'Human-authored first version',
        ]);
        $row = (array) DB::table('content_versions')->first();
        unset($row['id']);
        DB::table('content_versions')->insert(array_replace($row, [
            'version_number' => 2, 'body' => 'Later version',
        ]));
        $before = DB::table('content_versions')->orderBy('id')->get()->toJson();
        $this->backfillMigration()->up();
        $this->assertSame($before, DB::table('content_versions')->orderBy('id')->get()->toJson());
    }

    public function test_version_number_is_unique_per_content_piece(): void
    {
        ContentItem::factory()->create();
        $this->backfillMigration()->up();
        $row = (array) DB::table('content_versions')->first();
        unset($row['id']);
        $this->expectException(QueryException::class);
        DB::table('content_versions')->insert($row);
    }

    public function test_history_blocks_accidental_content_deletion(): void
    {
        $item = ContentItem::factory()->create();
        $this->backfillMigration()->up();
        $this->expectException(QueryException::class);
        DB::table('content_items')->where('id', $item->id)->delete();
    }
}
