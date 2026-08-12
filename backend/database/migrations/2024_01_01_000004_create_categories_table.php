<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id('CategoryID');
                $table->string('CategoryName', 100)->nullable();
                $table->text('Description')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('categories'); }
};
