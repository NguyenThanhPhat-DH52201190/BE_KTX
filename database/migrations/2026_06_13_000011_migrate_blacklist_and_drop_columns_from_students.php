<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('students') || ! Schema::hasColumn('students', 'is_blacklisted')) {
            return;
        }

        // Migrate existing blacklisted students into the new blacklist table
        // before dropping the flag columns. No code reads these columns, so the
        // only state to preserve is the data itself.
        if (Schema::hasTable('blacklist')) {
            $now = now();

            DB::table('students')
                ->where('is_blacklisted', 1)
                ->orderBy('id')
                ->each(function ($student) use ($now) {
                    $exists = DB::table('blacklist')->where('student_id', $student->id)->exists();
                    if ($exists) {
                        return;
                    }

                    DB::table('blacklist')->insert([
                        'student_id' => $student->id,
                        'reason' => $student->blacklist_reason ?: 'Migrated from students.is_blacklisted',
                        'source' => 'other',
                        'created_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
        }

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'blacklist_reason')) {
                $table->dropColumn('blacklist_reason');
            }
            if (Schema::hasColumn('students', 'is_blacklisted')) {
                $table->dropColumn('is_blacklisted');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('students')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'is_blacklisted')) {
                $table->boolean('is_blacklisted')->default(false)->after('current_year');
            }
            if (! Schema::hasColumn('students', 'blacklist_reason')) {
                $table->string('blacklist_reason')->nullable()->after('is_blacklisted');
            }
        });
    }
};
