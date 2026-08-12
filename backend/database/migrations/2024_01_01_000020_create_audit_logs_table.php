<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// This table already exists in the production database (see
// management_postgres.sql) but had no corresponding migration file.
// Added so `php artisan migrate:status` correctly reflects it, and so a
// fresh install gets an exact copy of the current schema.
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id')->nullable()->index('idx_user_id');
                $table->string('username', 50)->nullable();
                $table->string('action', 100)->index('idx_action');
                $table->string('resource_type', 50)->nullable();
                $table->integer('resource_id')->nullable();
                $table->text('details')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->useCurrent()->index('idx_created_at');

                $table->index(['resource_type', 'resource_id'], 'idx_resource');
            });
        }
    }
    public function down(): void { Schema::dropIfExists('audit_logs'); }
};
