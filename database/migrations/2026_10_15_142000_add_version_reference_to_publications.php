<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            // Nullable during transition; a reference alone never constitutes approval.
            $table->unsignedBigInteger('content_version_id')->nullable();
            $table->foreign(['content_item_id', 'content_version_id'])
                ->references(['content_item_id', 'id'])->on('content_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            $table->dropForeign(['content_item_id', 'content_version_id']);
            $table->dropColumn('content_version_id');
        });
    }
};
