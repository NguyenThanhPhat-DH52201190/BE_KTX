<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đổi giá trị enum registrations.status: 'pending' -> 'submitted'.
     * 'submitted' = "đã nộp, chờ chốt đợt" (đúng nghiệp vụ hơn 'pending').
     */
    public function up(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        // 1. Mở rộng enum để chứa cả hai giá trị trong lúc chuyển đổi.
        DB::statement("ALTER TABLE registrations MODIFY status ENUM('pending','submitted','approved','rejected') NOT NULL DEFAULT 'pending'");

        // 2. Migrate dữ liệu cũ.
        DB::table('registrations')->where('status', 'pending')->update(['status' => 'submitted']);

        // 3. Chốt enum mới, default thành 'submitted'.
        DB::statement("ALTER TABLE registrations MODIFY status ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('registrations')) {
            return;
        }

        DB::statement("ALTER TABLE registrations MODIFY status ENUM('pending','submitted','approved','rejected') NOT NULL DEFAULT 'submitted'");

        DB::table('registrations')->where('status', 'submitted')->update(['status' => 'pending']);

        DB::statement("ALTER TABLE registrations MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    }
};
