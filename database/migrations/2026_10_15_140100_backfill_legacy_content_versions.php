<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A single INSERT SELECT: no Eloquent hooks, publication sync or implicit approval.
        // Schema and data migrations are separate so failed DML can be retried on MySQL.
        // A committed import may outlive its migration ledger write. Preserve every
        // existing version-one row, regardless of provenance, when retrying.
        DB::table('content_versions')->insertUsing(
            ['content_item_id', 'version_number', 'title', 'body', 'context', 'content_type', 'image_path', 'created_by', 'provenance', 'created_at'],
            DB::table('content_items')->select([
                'id', DB::raw('1'), 'title', 'body', 'context', 'content_type', 'image_path',
                'submitted_by', DB::raw("'legacy_baseline'"), 'updated_at',
            ])->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('content_versions')
                    ->whereColumn('content_versions.content_item_id', 'content_items.id')
                    ->where('content_versions.version_number', 1);
            }),
        );
    }

    public function down(): void
    {
        DB::table('content_versions')->where('provenance', 'legacy_baseline')->where('version_number', 1)->delete();
    }
};
