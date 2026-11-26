<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_submission_receipts', function (Blueprint $table): void {
            // Existing correlation-only receipts remain explicitly unbound.
            $table->foreignId('agent_job_attempt_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('research_submission_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_job_attempt_id');
        });
    }
};
