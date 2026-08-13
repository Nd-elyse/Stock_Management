<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Covers installs where `stocktransactions` already existed (so the
// create-table migration's Schema::create() was skipped) but was created
// from the stale management.sql dump and is missing BeforeQty/AfterQty/UserID -
// columns the application (inventory.php, billing.php, and every
// StockTransaction::create() call in the API) has always written to.
return new class extends Migration {
    public function up(): void {
        Schema::table('stocktransactions', function (Blueprint $table) {
            if (!Schema::hasColumn('stocktransactions', 'BeforeQty')) {
                $table->integer('BeforeQty')->nullable();
            }
            if (!Schema::hasColumn('stocktransactions', 'AfterQty')) {
                $table->integer('AfterQty')->nullable();
            }
            if (!Schema::hasColumn('stocktransactions', 'UserID')) {
                $table->foreignId('UserID')->nullable()->constrained('users', 'UserID')->nullOnDelete();
            }
        });
    }

    public function down(): void {
        Schema::table('stocktransactions', function (Blueprint $table) {
            if (Schema::hasColumn('stocktransactions', 'UserID')) {
                $table->dropConstrainedForeignId('UserID');
            }
            if (Schema::hasColumn('stocktransactions', 'AfterQty')) {
                $table->dropColumn('AfterQty');
            }
            if (Schema::hasColumn('stocktransactions', 'BeforeQty')) {
                $table->dropColumn('BeforeQty');
            }
        });
    }
};
