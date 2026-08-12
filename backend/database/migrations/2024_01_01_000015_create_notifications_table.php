<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id('NotificationID');
                $table->foreignId('UserID')->nullable()->constrained('users', 'UserID')->cascadeOnDelete(); // null = broadcast to all
                $table->string('Type', 50)->nullable(); // system | job | stock | payment | ...
                $table->text('Message')->nullable();
                $table->smallInteger('IsRead')->default(0);
                $table->string('Link', 255)->nullable();
                $table->timestamp('CreatedAt')->nullable()->useCurrent();
                $table->string('Status')->nullable(); // Pending | Resolved
                $table->timestamp('ResolvedAt')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('notifications'); }
};
