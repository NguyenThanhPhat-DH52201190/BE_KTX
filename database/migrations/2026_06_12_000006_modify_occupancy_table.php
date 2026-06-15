<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

        // Temporarily disable foreign key checks to remove unique constraints
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Drop unique constraint on student_id using raw SQL
            DB::statement('ALTER TABLE occupancy DROP INDEX occupancy_student_id_unique');
        } catch (\Exception $e) {
            // Constraint may not exist
        }

        try {
            // Drop unique constraint on bed_id using raw SQL
            DB::statement('ALTER TABLE occupancy DROP INDEX occupancy_bed_id_unique');
        } catch (\Exception $e) {
            // Constraint may not exist
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Add new columns
        Schema::table('occupancy', function (Blueprint $table) {
            if (!Schema::hasColumn('occupancy', 'occupancy_status')) {
                $table->enum('occupancy_status', [
                    'active',
                    'completed',
                    'terminated',
                    'renewed'
                ])->default('active')->after('reason');
            }

            if (!Schema::hasColumn('occupancy', 'previous_occupancy_id')) {
                $table->foreignId('previous_occupancy_id')
                    ->nullable()
                    ->constrained('occupancy')
                    ->nullOnDelete()
                    ->after('occupancy_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('occupancy')) {
            return;
        }

        Schema::table('occupancy', function (Blueprint $table) {
            // Drop new columns
            if (Schema::hasColumn('occupancy', 'previous_occupancy_id')) {
                $table->dropConstrainedForeignId('previous_occupancy_id');
            }

            if (Schema::hasColumn('occupancy', 'occupancy_status')) {
                $table->dropColumn('occupancy_status');
            }
        });

        // Restore unique constraints
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::statement('ALTER TABLE occupancy ADD UNIQUE INDEX occupancy_student_id_unique (student_id)');
        } catch (\Exception $e) {
            // Index may already exist
        }

        try {
            DB::statement('ALTER TABLE occupancy ADD UNIQUE INDEX occupancy_bed_id_unique (bed_id)');
        } catch (\Exception $e) {
            // Index may already exist
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};

