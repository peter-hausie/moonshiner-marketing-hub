<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_principal_id')->constrained()->restrictOnDelete();
            $table->string('job_id', 128);
            $table->char('key_sha256', 64);
            $table->char('context_sha256', 64);
            $table->string('status', 24);
            $table->unsignedInteger('attempt_number')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['agent_principal_id', 'key_sha256'], 'agent_jobs_principal_key_unique');
        });
        Schema::create('agent_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_job_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 24);
            $table->string('error_code', 32)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unique(['agent_job_id', 'attempt_number'], 'agent_job_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_job_attempts');
        Schema::dropIfExists('agent_jobs');
    }
};
