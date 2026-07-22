<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đánh dấu admin đã "đề xuất duyệt" 1 hồ sơ giữ chỗ (nút Duyệt ở modal chi tiết) — KHÔNG
// đổi status ngay, để hồ sơ vẫn nằm trong tập submitted/waitlisted và được
// PriorityRankingService::rankReservationPeriod() xếp hạng công bằng cùng các hồ sơ khác.
// Chỉ khi chạy Xếp hạng cho đợt thì status mới thực sự chuyển approved/waitlisted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->timestamp('approve_proposed_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->dropColumn('approve_proposed_at');
        });
    }
};
