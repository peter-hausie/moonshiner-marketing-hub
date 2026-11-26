<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('api_key')->nullable();
            $table->string('writer_agent_id')->nullable();
            $table->string('research_agent_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('connector_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->unique()->constrained('content_agent_messages')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('status')->default('queued')->index();
            $table->json('context');
            $table->text('instruction');
            $table->unsignedInteger('connection_revision');
            $table->unsignedInteger('attempt')->default(0);
            $table->uuid('lease')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedBigInteger('result_version_id')->nullable();
            $table->json('result_ids')->nullable();
            $table->json('adoption_context')->nullable();
            $table->timestamp('adopted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_runs');
        Schema::dropIfExists('provider_connections');
    }
};
