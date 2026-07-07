<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Thêm 'cancelled' vào enum status — sinh viên tự hủy yêu cầu thôi ở đang pending
     * (giữ lại dòng để audit, không xóa). Cột sinh pending_occupancy_id chỉ nhận giá trị
     * khi status='pending' nên việc thêm enum mới không ảnh hưởng ràng buộc unique hiện có.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE checkout_requests MODIFY status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE checkout_requests MODIFY status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
    }
};
