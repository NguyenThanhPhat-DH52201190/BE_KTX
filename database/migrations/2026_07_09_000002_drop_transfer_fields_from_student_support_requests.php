<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bỏ tính năng tự động chuyển phòng/giường qua yêu cầu hỗ trợ — request_type (và các cột
// đích room_change/bed_change/roommate_request đi kèm) không còn được dùng, mọi đơn hỗ trợ
// giờ chỉ là 1 loại chung (tiêu đề + nội dung), không còn rẽ nhánh theo loại.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_support_requests', function (Blueprint $table) {
            $table->dropForeign(['target_room_id']);
            $table->dropForeign(['target_bed_id']);
            $table->dropForeign(['target_student_id']);
            $table->dropIndex('student_support_requests_request_type_index');
            $table->dropColumn(['request_type', 'target_room_id', 'target_bed_id', 'target_student_id']);
        });
    }

    public function down(): void
    {
        Schema::table('student_support_requests', function (Blueprint $table) {
            $table->enum('request_type', [
                'room_change',
                'bed_change',
                'roommate_request',
                'complaint',
                'suggestion',
                'maintenance_report',
                'other',
            ])->default('other')->after('student_id');
            $table->foreignId('target_room_id')->nullable()->after('attachment_url')->constrained('rooms')->nullOnDelete();
            $table->foreignId('target_bed_id')->nullable()->after('target_room_id')->constrained('beds')->nullOnDelete();
            $table->foreignId('target_student_id')->nullable()->after('target_bed_id')->constrained('students')->nullOnDelete();
            $table->index('request_type', 'student_support_requests_request_type_index');
        });
    }
};
