<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('purchases')) {
            Schema::create('purchases', function (Blueprint $table) {
                $table->id('PurchaseID');
                // No ON DELETE clause in the source DB (default restrict behaviour).
                $table->foreignId('SupplierID')->nullable()->constrained('suppliers', 'SupplierID');
                $table->foreignId('UserID')->nullable()->constrained('users', 'UserID')->nullOnDelete();
                $table->date('PurchaseDate')->nullable();
                $table->decimal('TotalAmount', 10, 2)->nullable();
                $table->string('Status')->default('Pending'); // Pending | Approved | Processed
            });
        }
    }
    public function down(): void { Schema::dropIfExists('purchases'); }
};
