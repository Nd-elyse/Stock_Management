<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('contactmessages')) {
            Schema::create('contactmessages', function (Blueprint $table) {
                $table->id('MessageID');
                $table->string('FullName', 100);
                $table->string('Email', 100);
                $table->string('Phone', 20)->nullable();
                $table->string('Subject', 150)->nullable();
                $table->text('Message');
                $table->smallInteger('IsRead')->default(0);
                $table->timestamp('CreatedAt')->nullable()->useCurrent();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('contactmessages'); }
};
