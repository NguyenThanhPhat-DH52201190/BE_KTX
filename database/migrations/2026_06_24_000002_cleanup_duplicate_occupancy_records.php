<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Xóa record ACTIVE không có registration_id (dữ liệu lỗi từ flow cũ)
        DB::table('occupancy')->whereNull('registration_id')->delete();

        // Xóa record ROOM_CONFIRMED trùng registration với record đã COMPLETED/ACTIVE
        // (giữ record có status hoàn chỉnh hơn, xóa record ROOM_CONFIRMED dư)
        DB::statement("
            DELETE o2
            FROM occupancy o2
            INNER JOIN occupancy o1
                ON o1.student_id      = o2.student_id
               AND o1.registration_id = o2.registration_id
               AND o1.id             != o2.id
            WHERE o2.status = 'ROOM_CONFIRMED'
              AND o1.status IN ('ACTIVE', 'COMPLETED', 'CHECKOUT_REQUESTED', 'TERMINATED')
        ");
    }

    public function down(): void
    {
        // Không thể rollback data deletion
    }
};
