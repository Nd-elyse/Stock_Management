<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('sparepartrequests')) {
            Schema::create('sparepartrequests', function (Blueprint $table) {
                $table->id('RequestID');
                $table->foreignId('MechanicID')->constrained('mechanics', 'MechanicID')->cascadeOnDelete();
                $table->foreignId('SparePartID')->constrained('spareparts', 'SparePartID')->cascadeOnDelete();
                $table->foreignId('JobID')->nullable()->constrained('repairjobs', 'JobID')->nullOnDelete();
                $table->integer('QuantityRequested');
                $table->string('Reason', 255)->nullable();
                $table->string('Status')->default('Pending'); // Pending | Fulfilled | Rejected
                $table->timestamp('RequestedAt')->useCurrent();
                $table->date('DecidedAt')->nullable();
                $table->foreignId('DecidedByUserID')->nullable()->constrained('users', 'UserID')->nullOnDelete();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('sparepartrequests'); }
};
