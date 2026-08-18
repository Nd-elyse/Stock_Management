<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repairjobs', function (Blueprint $table) {
            if (!Schema::hasColumn('repairjobs', 'Description')) {
                $table->text('Description')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repairjobs', function (Blueprint $table) {
            if (Schema::hasColumn('repairjobs', 'Description')) {
                $table->dropColumn('Description');
            }
        });
    }
};
