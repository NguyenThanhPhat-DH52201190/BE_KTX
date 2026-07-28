<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_REASON = 'period_closed_while_submitted';
    private const NEW_REASON = 'Hồ sơ không được xử lý trước khi đợt đăng ký kết thúc.';

    public function up(): void
    {
        if (!Schema::hasTable('dorm_reservations')) {
            return;
        }

        $updates = [
            'status'            => 'rejected',
            'rejection_reason'  => DB::raw("COALESCE(NULLIF(rejection_reason, ''), '" . self::NEW_REASON . "')"),
            'expiration_reason' => null,
        ];

        if (Schema::hasColumn('dorm_reservations', 'auto_decision')) {
            $updates['auto_decision'] = null;
        }

        if (Schema::hasColumn('dorm_reservations', 'auto_decision_reason')) {
            $updates['auto_decision_reason'] = null;
        }

        DB::table('dorm_reservations')
            ->where('status', 'expired')
            ->where('expiration_reason', self::OLD_REASON)
            ->update($updates);
    }

    public function down(): void
    {
        if (!Schema::hasTable('dorm_reservations')) {
            return;
        }

        DB::table('dorm_reservations')
            ->where('status', 'rejected')
            ->where('rejection_reason', self::NEW_REASON)
            ->update([
                'status'            => 'expired',
                'expiration_reason' => self::OLD_REASON,
                'rejection_reason'  => null,
            ]);
    }
};
