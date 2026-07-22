<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dọn dữ liệu mồ côi (priority cũ chưa gắn đơn) trước khi đổi ràng buộc
        DB::table('student_priority')->whereNull('registration_id')->delete();

        Schema::table('student_priority', function (Blueprint $table) {
            // FK student_id đang mượn index của unique cũ → thêm index riêng trước khi bỏ unique
            $table->index('student_id', 'student_priority_student_id_index');
        });

        Schema::table('student_priority', function (Blueprint $table) {
            // Bỏ unique cũ: 1 sinh viên / 1 tiêu chí trên toàn hệ thống
            $table->dropUnique('student_priority_student_id_priority_criteria_id_unique');
            // Unique mới: 1 đơn / 1 tiêu chí (cho phép cùng tiêu chí ở nhiều đơn khác nhau)
            $table->unique(['registration_id', 'priority_criteria_id'], 'student_priority_registration_criteria_unique');
        });
    }

    public function down(): void
    {
        Schema::table('student_priority', function (Blueprint $table) {
            $table->dropUnique('student_priority_registration_criteria_unique');
            $table->unique(['student_id', 'priority_criteria_id'], 'student_priority_student_id_priority_criteria_id_unique');
        });
    }
};
