<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('occupancy')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::statement('ALTER TABLE occupancy DROP INDEX occupancy_student_id_unique');
        } catch (\Exception $e) {
            // Index may not exist
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('occupancy')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::statement('ALTER TABLE occupancy ADD UNIQUE INDEX occupancy_student_id_unique (student_id)');
        } catch (\Exception $e) {
            // Index may already exist
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
