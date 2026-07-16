<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ghi nhận lý do cụ thể khi dorm_reservations.status chuyển sang 'expired' (do
// AutoCloseAdmissionPeriodsCommand) — để phân biệt được nhóm "đã duyệt giữ chỗ nhưng
// chưa nhập học" (Việc 5) khỏi các nhóm expired khác (chưa xét xong / còn ở danh sách
// chờ lúc đợt đóng). Không dùng enum DB, không dùng cancellation_reason/rejection_reason/
// admin_note vì 3 cột đó mang ý nghĩa khác (do người nhập, không phải hệ thống tự set).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->string('expiration_reason', 50)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->dropColumn('expiration_reason');
        });
    }
};
