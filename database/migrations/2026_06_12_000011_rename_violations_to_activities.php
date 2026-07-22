<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('violations')) {
            return;
        }

        // Rename table from violations to activities
        Schema::rename('violations', 'activities');

        // Update foreign key references in other tables
        if (Schema::hasTable('room_change_log')) {
            try {
                DB::statement('ALTER TABLE room_change_log DROP FOREIGN KEY room_change_log_violation_type_id_foreign');
            } catch (\Exception $e) {
                // Constraint may not exist
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('activities')) {
            return;
        }

        // Rename table back to violations
        Schema::rename('activities', 'violations');
    }
};
