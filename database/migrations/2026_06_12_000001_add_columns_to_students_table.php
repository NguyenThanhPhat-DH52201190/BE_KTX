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
        if (!Schema::hasTable('students')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'academic_status')) {
                $table->enum('academic_status', [
                    'studying',
                    'temporary_leave',
                    'dropped_out',
                    'suspended',
                    'waiting_graduation',
                    'graduated',
                    'overtime_training',
                    'transferred'
                ])->default('studying')->after('status');
            }

            if (!Schema::hasColumn('students', 'current_year')) {
                $table->tinyInteger('current_year')->unsigned()->nullable()->after('academic_status');
            }

            if (!Schema::hasColumn('students', 'is_blacklisted')) {
                $table->boolean('is_blacklisted')->default(false)->after('current_year');
            }

            if (!Schema::hasColumn('students', 'blacklist_reason')) {
                $table->string('blacklist_reason')->nullable()->after('is_blacklisted');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('students')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'blacklist_reason')) {
                $table->dropColumn('blacklist_reason');
            }

            if (Schema::hasColumn('students', 'is_blacklisted')) {
                $table->dropColumn('is_blacklisted');
            }

            if (Schema::hasColumn('students', 'current_year')) {
                $table->dropColumn('current_year');
            }

            if (Schema::hasColumn('students', 'academic_status')) {
                $table->dropColumn('academic_status');
            }
        });
    }
};
