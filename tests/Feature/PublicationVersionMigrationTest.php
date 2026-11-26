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
use Tests\TestCase;

class PublicationVersionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): object
    {
        return require database_path('migrations/2026_10_15_142100_backfill_publication_version_references.php');
    }

    private function fixture(): array
    {
        $item = ContentItem::factory()->create([
            'status' => ContentStatus::Approved, 'channels' => ['Blog', 'LinkedIn'],
        ]);
        $other = ContentItem::factory()->create();
        (require database_path('migrations/2026_10_15_140100_backfill_legacy_content_versions.php'))->up();

        return [$item, $other];
    }

    public function test_backfill_and_rollback_preserve_legacy_fields_without_approval_or_dispatch(): void
    {
        [$item] = $this->fixture();
        DB::table('publications')->where('channel', 'blog')->update([
            'status' => 'published', 'external_id' => 'preserved-id', 'attempts' => 3,
            'published_at' => '2026-10-14 10:00:00',
        ]);
        $before = DB::table('publications')->orderBy('id')->get()->toJson();
        Artisan::call('content-hub:inventory');
        $inventory = Artisan::output();
        Bus::fake();
        Http::preventStrayRequests();

        $this->backfill()->up();
        $version = DB::table('content_versions')->where('content_item_id', $item->id)->value('id');
        $this->assertSame(2, DB::table('publications')->where('content_version_id', $version)->count());
        Artisan::call('content-hub:inventory');
        $this->assertSame($inventory, Artisan::output());
        $bound = DB::table('publications')->orderBy('id')->get()->toJson();
        $this->backfill()->up();
        $this->assertSame($bound, DB::table('publications')->orderBy('id')->get()->toJson());
        $this->backfill()->down();
        $this->assertSame($before, DB::table('publications')->orderBy('id')->get()->toJson());
        $this->backfill()->up();
        $this->assertSame($bound, DB::table('publications')->orderBy('id')->get()->toJson());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_channel_bindings_are_independent_and_not_overwritten_by_backfill(): void
    {
        [$item] = $this->fixture();
        $row = (array) DB::table('content_versions')->where('content_item_id', $item->id)->first();
        unset($row['id']);
        $newVersion = DB::table('content_versions')->insertGetId(array_replace($row, [
            'version_number' => 2, 'body' => 'Channel-specific draft', 'provenance' => 'human_edit',
        ]));
        DB::table('publications')->where('channel', 'linkedin')->update(['content_version_id' => $newVersion]);
        $this->backfill()->up();
        $this->assertEquals($newVersion, DB::table('publications')->where('channel', 'linkedin')->value('content_version_id'));
        $this->assertNotEquals($newVersion, DB::table('publications')->where('channel', 'blog')->value('content_version_id'));
        $this->backfill()->down();
        $this->assertEquals($newVersion, DB::table('publications')->where('channel', 'linkedin')->value('content_version_id'));
    }

    public function test_database_rejects_version_of_another_piece(): void
    {
        [, $other] = $this->fixture();
        $otherVersion = DB::table('content_versions')->where('content_item_id', $other->id)->value('id');
        $this->expectException(QueryException::class);
        DB::table('publications')->update(['content_version_id' => $otherVersion]);
    }

    public function test_database_rejects_deletion_of_bound_version(): void
    {
        $this->fixture();
        $this->backfill()->up();
        $this->expectException(QueryException::class);
        DB::table('content_versions')->delete();
    }

    public function test_missing_baseline_remains_unbound_and_schema_can_roll_back(): void
    {
        ContentItem::factory()->create(['status' => ContentStatus::Approved, 'channels' => ['Blog']]);
        $this->backfill()->up();
        $this->assertNull(DB::table('publications')->value('content_version_id'));
        $migration = require database_path('migrations/2026_10_15_142000_add_version_reference_to_publications.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('publications', 1);
        $this->assertNull(DB::table('publications')->value('content_version_id'));
    }
}
