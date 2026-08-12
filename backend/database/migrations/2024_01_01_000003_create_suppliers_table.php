<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id('SupplierID');
                $table->string('CompanyName', 100)->nullable();
                $table->string('Phone', 20)->nullable();
                $table->string('Email', 100)->nullable();
                $table->string('Address', 150)->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('suppliers'); }
};
