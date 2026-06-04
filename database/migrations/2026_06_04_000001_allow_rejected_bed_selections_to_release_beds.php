<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('occupancy') || !Schema::hasColumn('occupancy', 'bed_id')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE occupancy DROP INDEX occupancy_bed_id_unique');
        } catch (\Throwable $exception) {
            // The index may already be absent on databases created from a newer schema.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('occupancy') || !Schema::hasColumn('occupancy', 'bed_id')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE occupancy ADD UNIQUE occupancy_bed_id_unique (bed_id)');
        } catch (\Throwable $exception) {
            // Existing duplicate historical selections can prevent restoring the old constraint.
        }
    }
};
