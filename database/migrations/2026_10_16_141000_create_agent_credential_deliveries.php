<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_credential_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_principal_id')->constrained()->restrictOnDelete();
            // Historical IDs survive revocation; never persist the bearer or its hash.
            $table->unsignedBigInteger('credential_id')->unique();
            $table->string('state', 16);
            // Explicit default prevents MariaDB's implicit first-TIMESTAMP ON UPDATE.
            $table->timestamp('recover_after')->useCurrent();
            $table->timestamps();
            $table->index(['state', 'recover_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_credential_deliveries');
    }
};
