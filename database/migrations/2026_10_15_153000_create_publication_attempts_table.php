<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            $table->unique(['id', 'content_item_id']);
        });
        Schema::create('publication_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('publication_id');
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('content_version_id')->nullable();
            $table->unsignedInteger('attempt_number');
            $table->string('status')->default('started');
            $table->string('provenance');
            // Explicit DEFAULT prevents MariaDB 10.6's implicit ON UPDATE on the
            // first TIMESTAMP from rewriting execution provenance during status changes.
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            // Store classified errors only, never provider responses or credentials.
            $table->string('error_code')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->unique(['publication_id', 'attempt_number']);
            $table->foreign(['publication_id', 'content_item_id'])->references(['id', 'content_item_id'])->on('publications')->restrictOnDelete();
            $table->foreign(['content_item_id', 'content_version_id'])->references(['content_item_id', 'id'])->on('content_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_attempts');
        Schema::table('publications', function (Blueprint $table): void {
            $table->dropUnique(['id', 'content_item_id']);
        });
    }
};
