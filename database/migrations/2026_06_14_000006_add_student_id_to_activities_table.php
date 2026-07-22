<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Thêm activities.student_id để query hoạt động theo sinh viên trực tiếp,
     * không phải join qua occupancy.
     */
    public function up(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        if (! Schema::hasColumn('activities', 'student_id')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->unsignedBigInteger('student_id')->nullable()->after('occupancy_id');
                $table->foreign('student_id')
                    ->references('id')
                    ->on('students')
                    ->nullOnDelete();
            });
        }

        // Backfill từ occupancy.
        if (Schema::hasColumn('activities', 'occupancy_id') && Schema::hasTable('occupancy')) {
            DB::statement('
                UPDATE activities a
                JOIN occupancy o ON o.id = a.occupancy_id
                SET a.student_id = o.student_id
                WHERE a.student_id IS NULL
            ');
            $filled = (int) DB::table('activities')->whereNotNull('student_id')->count();
            echo "  -> student_id đã có giá trị cho {$filled} dòng activities." . PHP_EOL;
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        if (Schema::hasColumn('activities', 'student_id')) {
            Schema::table('activities', function (Blueprint $table) {
                try {
                    $table->dropForeign(['student_id']);
                } catch (\Throwable $e) {
                    // FK có thể không tồn tại.
                }
                $table->dropColumn('student_id');
            });
        }
    }
};
