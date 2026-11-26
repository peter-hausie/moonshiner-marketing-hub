<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\Content\CreateContentVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class CreateContentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_preserves_exact_persisted_text_and_explicit_author_without_editorial_side_effects(): void
    {
        $item = ContentItem::factory()->create(['status' => ContentStatus::Approved, 'channels' => ['blog'], 'body' => "Original\n**Text**", 'context' => null]);
        $author = User::factory()->create();
        $beforeItem = $item->fresh()->getRawOriginal();
        $beforePublications = DB::table('publications')->get()->toJson();
        Bus::fake();
        Http::fake();
        $item->body = 'Unsaved caller edit';

        $id = app(CreateContentVersion::class)->create($item->id, $author->id, 'human_snapshot');

        $version = DB::table('content_versions')->find($id);
        foreach (['title', 'body', 'context', 'content_type', 'image_path'] as $field) {
            $this->assertSame($beforeItem[$field], $version->$field);
        }
        $this->assertSame($author->id, $version->created_by);
        $this->assertSame('human_snapshot', $version->provenance);
        $this->assertSame(1, $version->version_number);
        $this->assertSame($beforeItem, $item->fresh()->getRawOriginal());
        $this->assertSame($beforePublications, DB::table('publications')->get()->toJson());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_append_keeps_baseline_and_earlier_versions_unchanged_and_numbers_each_piece_separately(): void
    {
        $item = ContentItem::factory()->create();
        (require database_path('migrations/2026_10_15_140100_backfill_legacy_content_versions.php'))->up();
        $before = DB::table('content_versions')->first();
        DB::table('content_items')->where('id', $item->id)->update(['body' => 'Persisted next revision']);
        $service = app(CreateContentVersion::class);
        $second = $service->create($item->id, $item->submitted_by, 'human_snapshot');
        $secondBefore = DB::table('content_versions')->find($second);
        $third = $service->create($item->id, $item->submitted_by, 'human_snapshot');
        $other = ContentItem::factory()->create();
        $otherVersion = $service->create($other->id, $other->submitted_by, 'human_snapshot');

        $this->assertEquals($before, DB::table('content_versions')->find($before->id));
        $this->assertEquals($secondBefore, DB::table('content_versions')->find($second));
        $this->assertSame('Persisted next revision', $secondBefore->body);
        $this->assertSame(2, $secondBefore->version_number);
        $this->assertSame(3, DB::table('content_versions')->find($third)->version_number);
        $this->assertSame(1, DB::table('content_versions')->find($otherVersion)->version_number);
    }

    public function test_invalid_author_rolls_back_and_does_not_consume_version_number(): void
    {
        $item = ContentItem::factory()->create();
        $service = app(CreateContentVersion::class);
        try {
            $service->create($item->id, 999999, 'human_snapshot');
            $this->fail('Unknown author must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('content_versions', 0);
        }
        $id = $service->create($item->id, $item->submitted_by, 'human_snapshot');
        $this->assertSame(1, DB::table('content_versions')->find($id)->version_number);
    }

    public function test_missing_or_reserved_provenance_cannot_create_migration_owned_history(): void
    {
        $item = ContentItem::factory()->create();
        foreach (['', ' ', 'legacy_baseline', 'unstructured source', str_repeat('a', 65)] as $provenance) {
            try {
                app(CreateContentVersion::class)->create($item->id, $item->submitted_by, $provenance);
                $this->fail('Invalid provenance must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('content_versions', 0);
            }
        }
    }
}
