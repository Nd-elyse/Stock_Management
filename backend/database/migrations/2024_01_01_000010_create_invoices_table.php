<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id('InvoiceID');
                // No ON DELETE clause in the source DB (default restrict behaviour).
                $table->foreignId('CustomerID')->nullable()->constrained('customers', 'CustomerID');
                $table->foreignId('JobID')->nullable()->constrained('repairjobs', 'JobID')->nullOnDelete();
                $table->date('InvoiceDate')->nullable();
                $table->decimal('TotalAmount', 10, 2)->nullable();
                $table->decimal('LabourCharges', 10, 2)->default(0);
                $table->decimal('SparePartsCost', 10, 2)->default(0);
                $table->decimal('Taxes', 10, 2)->default(0);
                $table->decimal('Discounts', 10, 2)->default(0);
                $table->decimal('TaxRate', 5, 2)->default(18);
                $table->decimal('DiscountRate', 5, 2)->default(0);
                $table->foreignId('VehicleID')->nullable()->constrained('vehicles', 'VehicleID')->nullOnDelete();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('invoices'); }
};
