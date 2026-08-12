<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        // Guarded so this migration is a no-op there, while still creating an exact
        // match of the current schema on a fresh install.
        if (!Schema::hasTable('mechanics')) {
            Schema::create('mechanics', function (Blueprint $table) {
                $table->id('MechanicID');
                $table->string('FullName', 100)->nullable();
                $table->string('Phone', 20)->nullable();
                $table->string('Specialization', 100)->nullable();
                $table->decimal('Salary', 10, 2)->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('mechanics'); }
};
