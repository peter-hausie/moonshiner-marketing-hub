<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
        Schema::create('draft_submission_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_principal_id')->constrained()->restrictOnDelete();
            $table->string('job_id', 128);
            $table->char('key_sha256', 64);
            $table->char('payload_sha256', 64);
            $table->foreignId('content_version_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('accepted_at');
            $table->unique(['agent_principal_id', 'key_sha256'], 'draft_receipt_agent_key_unique');
        });
    }

    public function down(): void
    {
        // Never discard machine provenance or invent a human author on rollback.
        if (DB::table('content_versions')->whereNull('created_by')->exists()) {
            throw new RuntimeException('Machine-authored versions prevent rollback.');
        }
        Schema::dropIfExists('draft_submission_receipts');
        Schema::table('content_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
