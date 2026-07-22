<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bỏ cơ chế "chốt đợt thủ công" — nghiệp vụ cuối cùng chỉ có 1 mốc đóng thật
// là 17:00 của end_date, xử lý tự động bằng scheduler
// (registration-periods:auto-close-admission), không cần admin xác nhận tay.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('requires_student_code');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')
                ->constrained('accounts')->nullOnDelete();
        });
    }
};
