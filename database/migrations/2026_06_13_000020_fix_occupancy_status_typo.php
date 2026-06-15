<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix the misspelled enum value 'ACITVE' -> 'ACTIVE' on occupancy.status.
     * The two status dimensions are intentionally NOT merged; only the typo is
     * corrected, preserving every other enum value.
     */
    public function up(): void
    {
        if (! Schema::hasTable('occupancy') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE occupancy SET status = 'ACTIVE' WHERE status = 'ACITVE'");
        DB::statement("ALTER TABLE occupancy MODIFY status ENUM('OCCUPIED','ACTIVE','PROPOSED','CONFIRMED','CHECKOUT') NOT NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('occupancy') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE occupancy MODIFY status ENUM('OCCUPIED','ACITVE','PROPOSED','CONFIRMED','CHECKOUT') NOT NULL");
        DB::statement("UPDATE occupancy SET status = 'ACITVE' WHERE status = 'ACTIVE'");
    }
};
