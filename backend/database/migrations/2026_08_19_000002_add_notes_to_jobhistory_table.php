<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('jobhistory') && !Schema::hasColumn('jobhistory', 'Notes')) {
            Schema::table('jobhistory', function (Blueprint $table) {
                $table->text('Notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jobhistory') && Schema::hasColumn('jobhistory', 'Notes')) {
            Schema::table('jobhistory', function (Blueprint $table) {
                $table->dropColumn('Notes');
            });
        }
    }
};