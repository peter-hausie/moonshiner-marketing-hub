<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_items', function (Blueprint $table): void {
            $table->id();
            $table->text('source_url');
            $table->string('title');
            $table->longText('excerpt')->nullable();
            $table->timestamp('observed_at')->index();
            $table->string('provenance');
            // Null means unknown coverage, never evidence of an editorial gap.
            $table->json('coverage')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->longText('rationale')->nullable();
            $table->string('status')->default('proposed')->index();
            $table->string('provenance');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('opportunity_research_item', function (Blueprint $table): void {
            $table->foreignId('opportunity_id')->constrained()->restrictOnDelete();
            $table->foreignId('research_item_id')->constrained()->restrictOnDelete();
            $table->primary(['opportunity_id', 'research_item_id']);
        });

        Schema::create('content_item_opportunity', function (Blueprint $table): void {
            $table->foreignId('content_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_id')->constrained()->restrictOnDelete();
            $table->primary(['content_item_id', 'opportunity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_opportunity');
        Schema::dropIfExists('opportunity_research_item');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('research_items');
    }
};
