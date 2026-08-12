<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('spareparts')) {
            Schema::create('spareparts', function (Blueprint $table) {
                $table->id('SparePartID');
                $table->foreignId('CategoryID')->nullable()->constrained('categories', 'CategoryID')->nullOnDelete();
                $table->foreignId('SupplierID')->nullable()->constrained('suppliers', 'SupplierID')->nullOnDelete();
                $table->string('PartName', 100)->nullable();
                $table->decimal('UnitPrice', 10, 2)->nullable();
                $table->integer('Quantity')->nullable();
                $table->integer('ReorderLevel')->default(10);
            });
        }
    }
    public function down(): void { Schema::dropIfExists('spareparts'); }
};
