<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('sparepartrequests')) return;
        if (!Schema::hasColumn('sparepartrequests', 'UnitCost')) {
            Schema::table('sparepartrequests', fn (Blueprint $table) => $table->decimal('UnitCost', 10, 2)->nullable());
        }
        if (!Schema::hasColumn('sparepartrequests', 'TotalCost')) {
            Schema::table('sparepartrequests', fn (Blueprint $table) => $table->decimal('TotalCost', 12, 2)->nullable());
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('sparepartrequests')) return;
        if (Schema::hasColumn('sparepartrequests', 'UnitCost')) Schema::table('sparepartrequests', fn (Blueprint $table) => $table->dropColumn('UnitCost'));
        if (Schema::hasColumn('sparepartrequests', 'TotalCost')) Schema::table('sparepartrequests', fn (Blueprint $table) => $table->dropColumn('TotalCost'));
    }
};