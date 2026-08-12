<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Custom lightweight bearer-token store (Sanctum is not installed in this
// environment's vendor/ tree, so auth is implemented by hand: a random
// token is issued at login and its SHA-256 hash is stored here; requests
// authenticate via `Authorization: Bearer <token>` looked up by hash).
return new class extends Migration {
    public function up(): void {
        // Genuinely missing from the production database - this migration
        // actually creates it (guarded only for repeat-safety).
        if (!Schema::hasTable('auth_tokens')) {
            Schema::create('auth_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('UserID')->constrained('users', 'UserID')->cascadeOnDelete();
                $table->string('TokenHash')->unique();
                $table->timestamp('LastUsedAt')->nullable();
                $table->timestamp('ExpiresAt');
                $table->timestamp('CreatedAt')->useCurrent();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('auth_tokens'); }
};
