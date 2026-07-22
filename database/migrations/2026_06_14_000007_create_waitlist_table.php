<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Danh sách chờ: sinh viên bị từ chối "Hết chỗ" hoặc kênh quanh năm hết giường
     * muốn chờ khi có giường trống.
     */
    public function up(): void
    {
        if (Schema::hasTable('waitlist')) {
            return;
        }

        Schema::create('waitlist', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registration_id')
                ->constrained('registrations')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            // Lọc giường đúng giới tính.
            $table->enum('gender', ['male', 'female']);

            // Bậc/điểm ưu tiên copy từ registration.
            $table->tinyInteger('priority_tier')->default(99);
            $table->integer('priority_score')->default(0);

            // Vị trí trong hàng chờ (1 = đầu).
            $table->integer('queue_position');

            // Kênh: đợt chính hay quanh năm.
            $table->enum('source', ['main', 'rolling'])->default('main');

            $table->foreignId('registration_period_id')
                ->nullable()
                ->constrained('registration_periods')
                ->nullOnDelete();

            $table->enum('status', ['waiting', 'notified', 'converted', 'expired'])
                ->default('waiting');

            // Lúc hệ thống gửi mail báo có giường.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            // Hỗ trợ lấy hàng chờ theo trạng thái và thứ tự ưu tiên.
            $table->index(['status', 'priority_tier', 'priority_score'], 'waitlist_status_priority_index');
            $table->index(['registration_period_id', 'status'], 'waitlist_period_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist');
    }
};
