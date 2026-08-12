<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional line items on an invoice (e.g. specific spare parts sold on this
// job) - matches the original PHP app's invoiceitems table. Adding a spare
// part item here deducts stock and logs a "Sale" stocktransactions row,
// same as backend/api/billing.php did.
return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('invoiceitems')) {
            Schema::create('invoiceitems', function (Blueprint $table) {
                $table->id('InvoiceItemID');
                $table->foreignId('InvoiceID')->nullable()->constrained('invoices', 'InvoiceID')->cascadeOnDelete();
                $table->foreignId('SparePartID')->nullable()->constrained('spareparts', 'SparePartID')->nullOnDelete();
                $table->integer('Quantity')->nullable();
                $table->decimal('Price', 10, 2)->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('invoiceitems'); }
};
