<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns to accounts
        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'student_code')) {
                $table->string('student_code')->nullable()->after('email');
            }
            if (!Schema::hasColumn('accounts', 'full_name')) {
                $table->string('full_name')->nullable()->after('student_code');
            }
        });

        // Copy existing data from students -> accounts when student_id is set.
        // Only run updates for columns that actually exist on `students`.
        $hasStudentCode = Schema::hasColumn('students', 'student_code');
        $hasFullName = Schema::hasColumn('students', 'full_name');

        if ($hasStudentCode || $hasFullName) {
            $sets = [];
            if ($hasStudentCode) {
                $sets[] = 'a.student_code = s.student_code';
            }
            if ($hasFullName) {
                $sets[] = 'a.full_name = s.full_name';
            }

            $setSql = implode(', ', $sets);
            DB::statement("UPDATE accounts a JOIN students s ON a.student_id = s.id SET {$setSql}");
        }

        // Drop columns from students if they exist
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'student_code')) {
                $table->dropColumn('student_code');
            }
            if (Schema::hasColumn('students', 'full_name')) {
                $table->dropColumn('full_name');
            }
        });
    }

    public function down(): void
    {
        // Add columns back to students
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'student_code')) {
                $table->string('student_code')->unique()->after('id');
            }
            if (!Schema::hasColumn('students', 'full_name')) {
                $table->string('full_name')->after('avatar');
            }
        });

        // Copy values back from accounts to students when linked
        DB::statement('UPDATE students s JOIN accounts a ON a.student_id = s.id SET s.student_code = a.student_code, s.full_name = a.full_name');

        // Drop columns from accounts
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'student_code')) {
                $table->dropColumn('student_code');
            }
            if (Schema::hasColumn('accounts', 'full_name')) {
                $table->dropColumn('full_name');
            }
        });
    }
};
