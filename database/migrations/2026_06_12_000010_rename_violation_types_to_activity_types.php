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
        if (!Schema::hasTable('violation_types')) {
            return;
        }

        // Rename table from violation_types to activity_types
        Schema::rename('violation_types', 'activity_types');

        // Update foreign key references in violations/activities table
        if (Schema::hasTable('violations')) {
            DB::statement('ALTER TABLE violations DROP FOREIGN KEY violations_type_id_foreign');
            DB::statement('ALTER TABLE violations ADD CONSTRAINT violations_type_id_foreign FOREIGN KEY (type_id) REFERENCES activity_types(id) ON DELETE CASCADE');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('activity_types')) {
            return;
        }

        // Update foreign key references back
        if (Schema::hasTable('violations')) {
            DB::statement('ALTER TABLE violations DROP FOREIGN KEY violations_type_id_foreign');
            DB::statement('ALTER TABLE violations ADD CONSTRAINT violations_type_id_foreign FOREIGN KEY (type_id) REFERENCES violation_types(id) ON DELETE CASCADE');
        }

        // Rename table back to violation_types
        Schema::rename('activity_types', 'violation_types');
    }
};
