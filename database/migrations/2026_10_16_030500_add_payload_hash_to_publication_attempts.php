<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_attempts', function (Blueprint $table): void {
            // Legacy attempts intentionally have no executable payload binding.
            $table->char('payload_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('publication_attempts', function (Blueprint $table): void {
            $table->dropColumn('payload_sha256');
        });
    }
};
