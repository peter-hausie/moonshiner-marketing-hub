<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Match only the baseline for this piece. Do not rewrite a later explicit binding.
        DB::table('publications')->whereNull('content_version_id')->update([
            'content_version_id' => DB::table('content_versions')->select('id')
                ->whereColumn('content_versions.content_item_id', 'publications.content_item_id')
                ->where('version_number', 1)->where('provenance', 'legacy_baseline'),
        ]);
    }

    public function down(): void
    {
        DB::table('publications')->whereIn('content_version_id',
            DB::table('content_versions')->select('id')
                ->where('version_number', 1)->where('provenance', 'legacy_baseline')
        )->update(['content_version_id' => null]);
    }
};
