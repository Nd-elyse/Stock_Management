<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// This table already exists in the production database (see
// management_postgres.sql) but had no corresponding migration file.
// NOTE: the source table has no declared primary key (HistoryID is a
// plain NOT NULL integer, not a serial/identity column) - replicated
// exactly as-is below.
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('jobhistory')) {
            Schema::create('jobhistory', function (Blueprint $table) {
                $table->integer('HistoryID');
                $table->integer('JobID');
                $table->string('PreviousStatus')->nullable();
                $table->string('NewStatus')->index('idx_jobhistory_status');
                $table->foreignId('MechanicID')->nullable()->constrained('mechanics', 'MechanicID')->nullOnDelete();
                $table->string('MechanicName', 100)->nullable();
                $table->integer('ChangedByUserID')->nullable();
                $table->timestamp('ChangedAt')->useCurrent();
            });

            Schema::table('jobhistory', function (Blueprint $table) {
                $table->index('MechanicID', 'idx_jobhistory_mechanic');
            });
        }
    }
    public function down(): void { Schema::dropIfExists('jobhistory'); }
};
