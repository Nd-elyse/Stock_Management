<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id('CustomerID');
                $table->string('FullName', 100);
                $table->string('Phone', 20)->nullable();
                $table->string('Email', 100)->nullable();
                $table->string('Address', 200)->nullable();
                $table->date('RegistrationDate')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('customers'); }
};
