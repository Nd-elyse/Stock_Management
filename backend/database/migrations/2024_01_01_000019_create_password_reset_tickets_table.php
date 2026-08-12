<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Manual/admin-facing queue used when a user can't complete the
// self-service OTP reset flow (e.g. lost access to their email).
return new class extends Migration {
    public function up(): void {
        // Genuinely missing from the production database - this migration
        // actually creates it (guarded only for repeat-safety).
        if (!Schema::hasTable('password_reset_tickets')) {
            Schema::create('password_reset_tickets', function (Blueprint $table) {
                $table->id('RequestID');
                $table->string('Username');
                $table->string('Note')->nullable();
                $table->string('Status')->default('Pending'); // Pending | Resolved
                $table->timestamp('CreatedAt')->useCurrent();
                $table->timestamp('ResolvedAt')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('password_reset_tickets'); }
};
