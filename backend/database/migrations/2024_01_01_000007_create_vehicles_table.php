<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->id('VehicleID');
                $table->foreignId('CustomerID')->nullable()->constrained('customers', 'CustomerID')->nullOnDelete();
                $table->string('PlateNumber', 20)->nullable()->unique();
                $table->string('Manufacturer', 50)->nullable();
                $table->string('Model', 50)->nullable();
                $table->smallInteger('Year')->nullable();
                $table->string('ChassisNumber', 50)->nullable()->unique();
                $table->string('EngineNumber', 50)->nullable();
                $table->string('FuelType', 30)->nullable();
                $table->string('Transmission', 30)->nullable();
                $table->integer('Mileage')->nullable();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('vehicles'); }
};
