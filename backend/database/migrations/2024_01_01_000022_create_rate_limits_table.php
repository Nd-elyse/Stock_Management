<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// This table already exists in the production database (see
// management_postgres.sql) but had no corresponding migration file.
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('rate_limits')) {
            Schema::create('rate_limits', function (Blueprint $table) {
                $table->id();
                $table->string('identifier', 255);
                $table->string('endpoint', 100);
                $table->integer('attempt_count')->default(1);
                $table->timestamp('first_attempt')->useCurrent();
                $table->timestamp('last_attempt')->useCurrent();
                $table->timestamp('blocked_until')->nullable();

                $table->index(['identifier', 'endpoint'], 'idx_identifier_endpoint');
                $table->index('blocked_until', 'idx_blocked_until');
            });

            // MySQL's "ON UPDATE current_timestamp()" for last_attempt is replicated
            // with a BEFORE UPDATE trigger, since PostgreSQL has no column-level
            // ON UPDATE clause (mirrors management_postgres.sql exactly).
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared(<<<'SQL'
                    CREATE OR REPLACE FUNCTION set_rate_limits_last_attempt()
                    RETURNS TRIGGER AS $$
                    BEGIN
                      NEW."last_attempt" = CURRENT_TIMESTAMP;
                      RETURN NEW;
                    END;
                    $$ LANGUAGE plpgsql;

                    CREATE TRIGGER trg_rate_limits_last_attempt
                    BEFORE UPDATE ON "rate_limits"
                    FOR EACH ROW
                    EXECUTE FUNCTION set_rate_limits_last_attempt();
                SQL);
            }
        }
    }
    public function down(): void {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_rate_limits_last_attempt ON "rate_limits"');
            DB::unprepared('DROP FUNCTION IF EXISTS set_rate_limits_last_attempt()');
        }
        Schema::dropIfExists('rate_limits');
    }
};
