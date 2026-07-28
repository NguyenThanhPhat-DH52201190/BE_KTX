<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dorm_reservations')) {
            return;
        }

        $reasonExpression = Schema::hasColumn('dorm_reservations', 'cancellation_reason')
            ? "COALESCE(NULLIF(rejection_reason, ''), NULLIF(cancellation_reason, ''), 'Hồ sơ giữ chỗ đã bị hủy trước khi chuẩn hóa trạng thái.')"
            : "COALESCE(NULLIF(rejection_reason, ''), 'Hồ sơ giữ chỗ đã bị hủy trước khi chuẩn hóa trạng thái.')";
        $autoDecisionAssignments = Schema::hasColumn('dorm_reservations', 'auto_decision')
            ? ', auto_decision = NULL, auto_decision_reason = NULL'
            : '';

        DB::statement("
            UPDATE dorm_reservations
            SET status = 'rejected',
                rejection_reason = {$reasonExpression}
                {$autoDecisionAssignments}
            WHERE status = 'cancelled'
        ");

        DB::statement("
            ALTER TABLE dorm_reservations
            MODIFY status ENUM('submitted','approved','rejected','waitlisted','converted','expired') NOT NULL DEFAULT 'submitted'
        ");

        Schema::table('dorm_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('dorm_reservations', 'cancelled_by')) {
                $table->dropColumn('cancelled_by');
            }
            if (Schema::hasColumn('dorm_reservations', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            if (Schema::hasColumn('dorm_reservations', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dorm_reservations')) {
            return;
        }

        Schema::table('dorm_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('dorm_reservations', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            }
            if (! Schema::hasColumn('dorm_reservations', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('expiration_reason');
            }
            if (! Schema::hasColumn('dorm_reservations', 'cancelled_by')) {
                $table->string('cancelled_by', 30)->nullable()->after('cancelled_at');
            }
        });

        DB::statement("
            ALTER TABLE dorm_reservations
            MODIFY status ENUM('submitted','approved','rejected','waitlisted','converted','expired','cancelled') NOT NULL DEFAULT 'submitted'
        ");
    }
};
