<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('repairjobs')) {
            Schema::create('repairjobs', function (Blueprint $table) {
                $table->id('JobID');
                $table->foreignId('VehicleID')->nullable()->constrained('vehicles', 'VehicleID')->nullOnDelete();
                $table->foreignId('MechanicID')->nullable()->constrained('mechanics', 'MechanicID')->nullOnDelete();
                $table->foreignId('UserID')->nullable()->constrained('users', 'UserID')->nullOnDelete();
                $table->text('Description')->nullable();
                $table->date('StartDate')->nullable();
                $table->date('EndDate')->nullable();
                // Pending | Diagnosed | In Progress | Awaiting Parts | Ready |
                // Ready for Collection | Delivered | Cancelled
                $table->string('Status')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('repairjobs'); }
};
