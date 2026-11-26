<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('content_version_id')->constrained()->restrictOnDelete();
            $table->string('channel');
            $table->char('fingerprint', 64);
            $table->enum('decision', ['approved', 'revoked']);
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['publication_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_reviews');
    }
};
