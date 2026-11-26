<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_editorial_settings', function (Blueprint $table): void {
            $table->string('role')->primary();
            $table->string('display_name', 100);
            $table->text('briefing')->nullable();
            $table->json('competitors')->nullable();
            $table->boolean('monitoring_required')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_editorial_settings');
    }
};
