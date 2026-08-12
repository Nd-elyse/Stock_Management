<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id('UserID');
                $table->foreignId('MechanicID')->nullable()->constrained('mechanics', 'MechanicID')->nullOnDelete();
                $table->string('Username', 50)->nullable()->unique();
                $table->string('Password', 260)->nullable(); // hashed
                $table->string('Role')->nullable(); // Admin | Receptionist | Mechanic | Stock Manager
                $table->string('FullName', 100)->nullable();
                $table->string('Email', 100)->nullable();
                $table->string('Phone', 20)->nullable();
                $table->string('Status')->default('Inactive'); // Active while logged in, else Inactive
                $table->timestamp('LastActivity')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('users'); }
};
