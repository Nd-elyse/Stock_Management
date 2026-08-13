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
                // Present in the live app (backend/api/inventory.php + billing.php insert these on every
                // transaction) but missing from the stale management.sql dump/original migration - restored
                // here so stock movements can be audited with before/after quantities and the acting user.
                $table->integer('BeforeQty')->nullable();
                $table->integer('AfterQty')->nullable();
                $table->foreignId('UserID')->nullable()->constrained('users', 'UserID')->nullOnDelete();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('stocktransactions'); }
};
