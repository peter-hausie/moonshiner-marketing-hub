<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table): void {
            $table->id();
            // Keep the existing ContentItem identity; history prevents accidental deletion.
            $table->foreignId('content_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->longText('body');
            $table->text('context')->nullable();
            $table->string('content_type');
            $table->string('image_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('provenance');
            $table->timestamp('created_at')->nullable();
            $table->unique(['content_item_id', 'version_number']);
            $table->unique(['content_item_id', 'id'], 'content_versions_piece_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_versions');
    }
};
