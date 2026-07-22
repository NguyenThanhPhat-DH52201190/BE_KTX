<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('activities')) {
            return;
        }

        Schema::table('activities', function (Blueprint $table) {
            // Rename violation_date to activity_date if exists
            if (Schema::hasColumn('activities', 'violation_date') && !Schema::hasColumn('activities', 'activity_date')) {
                $table->renameColumn('violation_date', 'activity_date');
            }

            // Rename type_id to activity_type_id if exists
            if (Schema::hasColumn('activities', 'type_id') && !Schema::hasColumn('activities', 'activity_type_id')) {
                $table->renameColumn('type_id', 'activity_type_id');
            }

            // Add activity_date if it doesn't exist and violation_date doesn't exist
            if (!Schema::hasColumn('activities', 'activity_date') && !Schema::hasColumn('activities', 'violation_date')) {
                $table->date('activity_date')->after('activity_type_id');
            }

            // Add points_earned if it doesn't exist
            if (!Schema::hasColumn('activities', 'points_earned')) {
                $table->integer('points_earned')->default(0)->after('action_taken');
            }

            // Add timestamps if not present
            if (!Schema::hasColumn('activities', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('activities')) {
            return;
        }

        Schema::table('activities', function (Blueprint $table) {
            // Remove timestamps if added by this migration
            if (Schema::hasColumn('activities', 'created_at')) {
                $table->dropColumn(['created_at', 'updated_at']);
            }

            // Remove points_earned
            if (Schema::hasColumn('activities', 'points_earned')) {
                $table->dropColumn('points_earned');
            }

            // Restore old column names
            if (Schema::hasColumn('activities', 'activity_date') && !Schema::hasColumn('activities', 'violation_date')) {
                $table->renameColumn('activity_date', 'violation_date');
            }

            if (Schema::hasColumn('activities', 'activity_type_id') && !Schema::hasColumn('activities', 'type_id')) {
                $table->renameColumn('activity_type_id', 'type_id');
            }
        });
    }
};
