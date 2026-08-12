<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id('PaymentID');
                $table->foreignId('InvoiceID')->nullable()->constrained('invoices', 'InvoiceID')->cascadeOnDelete();
                $table->decimal('Amount', 10, 2)->nullable();
                $table->string('PaymentMethod', 30)->nullable();
                $table->string('PaymentStatus')->nullable(); // Pending | Partial | Paid
                $table->date('PaymentDate')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('payments'); }
};
