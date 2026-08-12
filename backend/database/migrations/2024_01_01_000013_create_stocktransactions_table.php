<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('stocktransactions')) {
            Schema::create('stocktransactions', function (Blueprint $table) {
                $table->id('TransactionID');
                $table->foreignId('SparePartID')->nullable()->constrained('spareparts', 'SparePartID')->cascadeOnDelete();
                $table->string('TransactionType')->nullable(); // Purchase | Usage | Adjustment | Sale | Restoration | Pending
                $table->integer('Quantity')->nullable();
                $table->date('TransactionDate')->nullable();
                $table->foreignId('PurchaseID')->nullable()->constrained('purchases', 'PurchaseID')->nullOnDelete();
                $table->decimal('UnitPrice', 10, 2)->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('stocktransactions'); }
};
