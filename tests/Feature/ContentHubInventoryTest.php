<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentHubInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function inventory(): array
    {
        $this->assertSame(0, Artisan::call('content-hub:inventory'));
        $output = Artisan::output();
        $this->assertStringNotContainsString('Confidential draft', $output);
        $this->assertStringNotContainsString('password', $output);
        $this->assertStringNotContainsString('@', $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_inventory_is_repeatable_and_does_not_change_records_or_dispatch_work(): void
    {
        $item = ContentItem::factory()->create([
            'title' => 'Confidential draft',
            'status' => ContentStatus::Approved,
            'channels' => ['Blog'],
        ]);
        Bus::fake();
        Http::preventStrayRequests();
        $before = [];
        foreach (['users', 'content_items', 'publications'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }

        $first = $this->inventory();
        $this->assertSame($first, $this->inventory());
        $this->assertSame(1, $first['tables']['content_items']['count']);
        $this->assertSame(1, $first['tables']['publications']['count']);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson());
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();

        // Same count but changed text must not pass a migration comparison.
        DB::table('content_items')->where('id', $item->id)->update(['body' => 'Changed']);
        $second = $this->inventory();
        $this->assertNotSame($first['tables']['content_items']['sha256'], $second['tables']['content_items']['sha256']);
        $this->assertSame($first['tables']['publications'], $second['tables']['publications']);

        DB::table('users')->where('id', $item->submitted_by)->update(['role' => 'curator']);
        $third = $this->inventory();
        $this->assertNotSame($second['tables']['users']['sha256'], $third['tables']['users']['sha256']);
    }

    public function test_empty_inventory_is_valid_and_additive_columns_do_not_change_fingerprint(): void
    {
        $before = $this->inventory();
        $this->assertSame(0, $before['tables']['users']['count']);
        Schema::table('content_items', function ($table) {
            $table->string('future_column')->nullable();
        });
        $this->assertSame($before, $this->inventory());
    }
}
