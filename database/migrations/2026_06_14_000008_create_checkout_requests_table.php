<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Yêu cầu thôi ở của sinh viên (thay cho cột cờ occupancy.checkout_requested).
     */
    public function up(): void
    {
        if (Schema::hasTable('checkout_requests')) {
            return;
        }

        Schema::create('checkout_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('occupancy_id')
                ->constrained('occupancy')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->text('reason');
            $table->date('expected_leave_date');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->unsignedBigInteger('processed_by')->nullable();
            $table->foreign('processed_by')
                ->references('id')
                ->on('accounts')
                ->nullOnDelete();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // Mỗi occupancy chỉ có 1 yêu cầu đang 'pending' tại một thời điểm.
            // Cột sinh: = occupancy_id khi pending, NULL khi không (NULL không xung đột unique).
            $table->unsignedBigInteger('pending_occupancy_id')
                ->nullable()
                ->virtualAs("(CASE WHEN status = 'pending' THEN occupancy_id ELSE NULL END)");
            $table->unique('pending_occupancy_id', 'checkout_requests_unique_pending_per_occupancy');

            $table->index('status', 'checkout_requests_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_requests');
    }
};
