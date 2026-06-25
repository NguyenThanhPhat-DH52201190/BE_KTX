<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Dọn hóa đơn test trước tháng 6/2026
        $deleted = DB::table('room_fee_bills')
            ->where(function ($q) {
                $q->where('year', '<', 2026)
                  ->orWhere(function ($q2) {
                      $q2->where('year', 2026)->where('month', '<', 6);
                  });
            })
            ->delete();
        echo "Deleted stale bills: {$deleted}\n";

        // Thêm PENDING_PAYMENT vào enum occupancy.status
        DB::statement("
            ALTER TABLE occupancy
            MODIFY COLUMN status ENUM(
                'PROPOSED',
                'ROOM_CONFIRMED',
                'PENDING_PAYMENT',
                'ACTIVE',
                'COMPLETED',
                'TERMINATED',
                'CANCELLED'
            ) NOT NULL DEFAULT 'PROPOSED'
        ");
        echo "Added PENDING_PAYMENT to occupancy.status enum\n";
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE occupancy
            MODIFY COLUMN status ENUM(
                'PROPOSED',
                'ROOM_CONFIRMED',
                'ACTIVE',
                'COMPLETED',
                'TERMINATED',
                'CANCELLED'
            ) NOT NULL DEFAULT 'PROPOSED'
        ");
    }
};
