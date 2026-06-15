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
        // Drop points_earned from activities table
        if (Schema::hasTable('activities') && Schema::hasColumn('activities', 'points_earned')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropColumn('points_earned');
            });
        }

        // Drop priority_score from registrations table
        if (Schema::hasTable('registrations') && Schema::hasColumn('registrations', 'priority_score')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->dropColumn('priority_score');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore points_earned to activities table
        if (Schema::hasTable('activities') && !Schema::hasColumn('activities', 'points_earned')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->integer('points_earned')->default(0)->after('action_taken');
            });
        }

        // Restore priority_score to registrations table
        if (Schema::hasTable('registrations') && !Schema::hasColumn('registrations', 'priority_score')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->integer('priority_score')->default(0)->after('registration_type');
            });
        }
    }
};
