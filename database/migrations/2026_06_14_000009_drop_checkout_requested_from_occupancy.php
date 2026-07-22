<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Xóa cột cờ occupancy.checkout_requested — đã thay bằng bảng checkout_requests
     * (sự tồn tại bản ghi status='pending' = đang có yêu cầu thôi ở).
     */
    public function up(): void
    {
        if (! Schema::hasTable('occupancy')) {
            return;
        }

        if (Schema::hasColumn('occupancy', 'checkout_requested')) {
            Schema::table('occupancy', function (Blueprint $table) {
                $table->dropColumn('checkout_requested');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('occupancy')) {
            return;
        }

        if (! Schema::hasColumn('occupancy', 'checkout_requested')) {
            Schema::table('occupancy', function (Blueprint $table) {
                $table->boolean('checkout_requested')->default(false)->after('bed_approval_status');
            });
        }

        // Khôi phục cờ từ các yêu cầu đang chờ (nếu có bảng checkout_requests).
        if (Schema::hasTable('checkout_requests')) {
            DB::statement("
                UPDATE occupancy o
                SET o.checkout_requested = 1
                WHERE EXISTS (
                    SELECT 1 FROM checkout_requests c
                    WHERE c.occupancy_id = o.id AND c.status = 'pending'
                )
            ");
        }
    }
};
