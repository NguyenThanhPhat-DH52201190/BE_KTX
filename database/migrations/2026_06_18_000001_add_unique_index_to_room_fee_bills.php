<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Thêm unique index (student_id, month, year) vào room_fee_bills.
     * Một sinh viên chỉ có một hóa đơn tiền phòng cho mỗi tháng.
     */
    public function up(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        // Xóa bản ghi trùng lặp trước khi thêm unique index.
        // Giữ lại bản ghi có id nhỏ nhất trong mỗi nhóm (student_id, month, year).
        DB::statement('
            DELETE r1 FROM room_fee_bills r1
            INNER JOIN room_fee_bills r2
                ON  r1.student_id = r2.student_id
                AND r1.month      = r2.month
                AND r1.year       = r2.year
                AND r1.id         > r2.id
        ');

        Schema::table('room_fee_bills', function (Blueprint $table) {
            $table->unique(['student_id', 'month', 'year'], 'room_fee_bills_student_month_year_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        Schema::table('room_fee_bills', function (Blueprint $table) {
            $table->dropUnique('room_fee_bills_student_month_year_unique');
        });
    }
};
