<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Table already exists in the production database (see management_postgres.sql).
        if (!Schema::hasTable('diagnostics')) {
            Schema::create('diagnostics', function (Blueprint $table) {
                $table->id('DiagnosticID');
                $table->foreignId('JobID')->constrained('repairjobs', 'JobID')->cascadeOnDelete();
                $table->foreignId('MechanicID')->constrained('mechanics', 'MechanicID')->cascadeOnDelete();
                $table->date('DiagnosticDate');
                $table->text('Notes');
                $table->string('Recommendation', 100)->nullable();
                $table->decimal('EstimatedCost', 10, 2)->nullable();
                $table->timestamp('CreatedAt')->useCurrent();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('diagnostics'); }
};
