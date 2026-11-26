<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_items', function (Blueprint $table): void {
            $table->dateTime('published_at')->nullable();
            $table->dateTime('event_at')->nullable();
        });
        Schema::create('research_submission_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_principal_id')->constrained()->restrictOnDelete();
            $table->string('job_id', 128);
            // Hash preserves case-sensitive key identity across database collations.
            $table->char('key_sha256', 64);
            $table->char('payload_sha256', 64);
            $table->foreignId('research_item_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('accepted_at');
            $table->unique(['agent_principal_id', 'key_sha256'], 'research_receipt_agent_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_submission_receipts');
        Schema::table('research_items', function (Blueprint $table): void {
            $table->dropColumn(['published_at', 'event_at']);
        });
    }
};
