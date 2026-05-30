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
        if (!Schema::hasTable('accounts')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'student_code')) {
                try {
                    $table->dropUnique('accounts_student_code_unique');
                } catch (\Throwable $e) {
                    // Ignore if the unique index does not exist.
                }
                $table->dropColumn('student_code');
            }

            if (Schema::hasColumn('accounts', 'email')) {
                try {
                    $table->dropUnique('accounts_email_unique');
                } catch (\Throwable $e) {
                    // Ignore if the unique index does not exist.
                }
                $table->dropColumn('email');
            }

            if (Schema::hasColumn('accounts', 'is_active')) {
                $table->dropColumn('is_active');
            }

            if (!Schema::hasColumn('accounts', 'student_id')) {
                $table->foreignId('student_id')->nullable()->after('id');
            }
        });

        if (Schema::hasTable('accounts') && Schema::hasColumn('accounts', 'student_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                try {
                    $table->unique('student_id');
                } catch (\Throwable $e) {
                    // Ignore if the unique index already exists.
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('accounts')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'email')) {
                $table->string('email')->nullable()->unique()->after('student_id');
            }

            if (!Schema::hasColumn('accounts', 'student_code')) {
                $table->string('student_code')->nullable()->unique()->after('student_id');
            }

            if (!Schema::hasColumn('accounts', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('role');
            }
        });
    }
};