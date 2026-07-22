<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhận diện chắc chắn Registration được tạo từ DormReservation (giữ chỗ tân sinh viên),
 * độc lập với dorm_reservations.converted_registration_id — field đó chỉ được set SAU KHI
 * admin xác nhận duyệt (dùng để loại reservation khỏi capacity counter approved_dorm_reservations),
 * nên không dùng được để nhận diện trong lúc Registration còn ở trạng thái "chờ xác nhận".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('source_dorm_reservation_id')->nullable()
                ->after('registration_type')
                ->constrained('dorm_reservations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_dorm_reservation_id');
        });
    }
};
