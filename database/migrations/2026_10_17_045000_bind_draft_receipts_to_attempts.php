<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_submission_receipts', function (Blueprint $table): void {
            // Existing correlation-only receipts remain explicitly unbound.
            $table->foreignId('agent_job_attempt_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('draft_submission_receipts')->whereNotNull('agent_job_attempt_id')->exists()) {
            throw new RuntimeException('Attempt-bound draft receipts prevent rollback.');
        }
        Schema::table('draft_submission_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_job_attempt_id');
        });
    }
};
