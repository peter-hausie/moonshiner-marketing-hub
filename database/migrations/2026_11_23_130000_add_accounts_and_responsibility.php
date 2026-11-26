<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('active')->default(true);
            $table->boolean('is_admin')->default(false);
        });
        // Preserve administration for existing installations without a new default password.
        DB::table('users')->where('role', 'curator')->update(['is_admin' => true]);
        Schema::table('content_items', function (Blueprint $table): void {
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('content_items')->update(['responsible_user_id' => DB::raw('submitted_by')]);
        Schema::create('account_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
        Schema::table('content_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('responsible_user_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['active', 'is_admin']));
    }
};
