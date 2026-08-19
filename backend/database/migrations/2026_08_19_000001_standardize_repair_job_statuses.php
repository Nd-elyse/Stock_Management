<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('repairjobs')) return;

        DB::table('repairjobs')->where('Status', 'In Progress')->update(['Status' => 'InProgress']);
        DB::table('repairjobs')->where('Status', 'Awaiting Parts')->update(['Status' => 'AwaitingParts']);
        DB::table('repairjobs')->where('Status', 'Completed')->update(['Status' => 'Ready']);
    }

    public function down(): void
    {
        // Canonical statuses are intentionally retained on rollback so older
        // application versions do not receive ambiguous workflow values.
    }
};