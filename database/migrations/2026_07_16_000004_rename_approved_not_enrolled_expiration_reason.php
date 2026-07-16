<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Đổi tên giá trị expiration_reason 'approved_not_enrolled' -> 'approved_not_converted'
// cho khớp nghiệp vụ mới: candidate.status=enrolled không còn đồng nghĩa reservation đã
// converted (submitted/waitlisted cũng tạo Student được). Migration
// 2026_07_16_000003_add_expiration_reason... đã chạy trong DB nên KHÔNG sửa lại migration
// đó — chỉ update dữ liệu ở đây, không mất dữ liệu.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dorm_reservations')
            ->where('expiration_reason', 'approved_not_enrolled')
            ->update(['expiration_reason' => 'approved_not_converted']);
    }

    public function down(): void
    {
        DB::table('dorm_reservations')
            ->where('expiration_reason', 'approved_not_converted')
            ->update(['expiration_reason' => 'approved_not_enrolled']);
    }
};
