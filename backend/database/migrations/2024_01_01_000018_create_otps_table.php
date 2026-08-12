<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Genuinely missing from the production database - this migration
        // actually creates it (guarded only for repeat-safety).
        if (!Schema::hasTable('otps')) {
            Schema::create('otps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('UserID')->constrained('users', 'UserID')->cascadeOnDelete();
                $table->string('Purpose')->default('login'); // login | password_reset
                $table->string('CodeHash');
                $table->unsignedTinyInteger('Attempts')->default(0);
                $table->boolean('Consumed')->default(false);
                $table->timestamp('VerifiedAt')->nullable(); // password_reset: set once the code is confirmed, checked again at the final reset step
                $table->timestamp('ExpiresAt');
                $table->timestamp('CreatedAt')->useCurrent();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('otps'); }
};
