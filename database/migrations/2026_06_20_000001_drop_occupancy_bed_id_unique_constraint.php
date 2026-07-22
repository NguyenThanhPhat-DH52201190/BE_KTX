<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('occupancy')) {
            return;
        }

        $hasUnique = collect(DB::select("SHOW INDEX FROM `occupancy` WHERE Key_name = 'occupancy_bed_id_unique'"))->isNotEmpty();
        if (!$hasUnique) {
            return;
        }

        // MySQL prevents dropping a unique index when a FK uses it as its index.
        // Drop the FK first, replace unique with a plain index, then restore the FK.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::statement('ALTER TABLE occupancy DROP FOREIGN KEY occupancy_bed_id_foreign');
        } catch (\Exception $e) {
            // FK may already be gone
        }

        DB::statement('ALTER TABLE occupancy DROP INDEX occupancy_bed_id_unique');

        // Restore the FK — MySQL auto-creates a plain (non-unique) index for it.
        try {
            DB::statement('ALTER TABLE occupancy ADD CONSTRAINT occupancy_bed_id_foreign FOREIGN KEY (bed_id) REFERENCES beds (id) ON DELETE CASCADE');
        } catch (\Exception) {
            // If the FK already exists under another name, leave it as-is.
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        if (!Schema::hasTable('occupancy')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::statement('ALTER TABLE occupancy DROP FOREIGN KEY occupancy_bed_id_foreign');
        } catch (\Exception $e) {}

        try {
            DB::statement('ALTER TABLE occupancy ADD UNIQUE INDEX occupancy_bed_id_unique (bed_id)');
        } catch (\Exception $e) {}

        try {
            DB::statement('ALTER TABLE occupancy ADD CONSTRAINT occupancy_bed_id_foreign FOREIGN KEY (bed_id) REFERENCES beds (id) ON DELETE CASCADE');
        } catch (\Exception $e) {}

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
