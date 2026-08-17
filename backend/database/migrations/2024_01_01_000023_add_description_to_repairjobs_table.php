<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Add/Edit Repair Job form has always collected a required "Description"
// field, but the repairjobs table never had a matching column - every
// description a receptionist typed was silently discarded on save. This
// migration adds the missing column so that data actually persists.
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('repairjobs', 'Description')) {
            Schema::table('repairjobs', function (Blueprint $table) {
                $table->text('Description')->nullable()->after('MechanicID');
            });
        }
    }
    public function down(): void {
        if (Schema::hasColumn('repairjobs', 'Description')) {
            Schema::table('repairjobs', function (Blueprint $table) {
                $table->dropColumn('Description');
            });
        }
    }
};
