<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend enum to include room_change and bed_change
        DB::statement("ALTER TABLE student_support_requests MODIFY COLUMN request_type ENUM('room_change','bed_change','roommate_request','complaint','suggestion','maintenance_report','other') NOT NULL");

        Schema::table('student_support_requests', function (Blueprint $table) {
            $table->foreignId('target_room_id')->nullable()->after('attachment_url')->constrained('rooms')->nullOnDelete();
            $table->foreignId('target_bed_id')->nullable()->after('target_room_id')->constrained('beds')->nullOnDelete();
            $table->foreignId('target_student_id')->nullable()->after('target_bed_id')->constrained('students')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_support_requests', function (Blueprint $table) {
            $table->dropForeign(['target_room_id']);
            $table->dropForeign(['target_bed_id']);
            $table->dropForeign(['target_student_id']);
            $table->dropColumn(['target_room_id', 'target_bed_id', 'target_student_id']);
        });

        DB::statement("ALTER TABLE student_support_requests MODIFY COLUMN request_type ENUM('roommate_request','complaint','suggestion','maintenance_report','other') NOT NULL");
    }
};
