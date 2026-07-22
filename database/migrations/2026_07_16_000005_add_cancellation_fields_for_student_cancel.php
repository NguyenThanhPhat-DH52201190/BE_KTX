<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Luồng "Hủy nhu cầu ở KTX trước deadline": dorm_reservations.status đã có sẵn giá trị
// 'cancelled' (enum gốc từ create_dorm_reservations_table) và cancellation_reason đã có
// (2026_07_15_000001) — chỉ thiếu cancelled_at/cancelled_by. registrations thì thiếu cả
// giá trị 'cancelled' trong enum status lẫn 3 cột cancelled_at/cancellation_reason/
// cancelled_by (bảng này trước giờ không có khái niệm hủy tự phục vụ).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dorm_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('dorm_reservations', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('expiration_reason');
            }
            if (! Schema::hasColumn('dorm_reservations', 'cancelled_by')) {
                $table->string('cancelled_by', 30)->nullable()->after('cancelled_at');
            }
        });

        DB::statement("ALTER TABLE registrations MODIFY status ENUM('submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'submitted'");

        Schema::table('registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('registrations', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('registrations', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('registrations', 'cancelled_by')) {
                $table->string('cancelled_by', 30)->nullable()->after('cancellation_reason');
            }
        });
    }

    public function down(): void
    {
        // An toàn trước — dừng hẳn nếu rollback sẽ làm mất dữ liệu hủy thật (registrations.
        // status='cancelled' không còn nằm trong enum sau rollback, và cancelled_at/
        // cancellation_reason/cancelled_by ở cả 2 bảng sẽ bị xóa cột). KHÔNG tự ý đổi
        // status='cancelled' sang giá trị khác để "cho qua" — làm sai lịch sử hủy thật.
        $cancelledRegistrations = Schema::hasColumn('registrations', 'status')
            ? DB::table('registrations')->where('status', 'cancelled')->count()
            : 0;

        if ($cancelledRegistrations > 0) {
            throw new RuntimeException(
                "Cannot rollback while cancelled registrations exist ({$cancelledRegistrations} row(s) with registrations.status='cancelled'). "
                . 'Rolling back would drop cancellation_reason/cancelled_at/cancelled_by and remove \'cancelled\' from the status enum, silently corrupting cancellation history. '
                . 'Resolve or archive these rows manually before rolling back this migration.'
            );
        }

        $cancelledReservations = Schema::hasColumn('dorm_reservations', 'cancelled_at')
            ? DB::table('dorm_reservations')->whereNotNull('cancelled_at')->count()
            : 0;

        if ($cancelledReservations > 0) {
            throw new RuntimeException(
                "Cannot rollback while dorm_reservations rows have cancelled_at set ({$cancelledReservations} row(s)). "
                . 'Rolling back would silently drop cancelled_at/cancelled_by. Resolve or archive these rows manually before rolling back this migration.'
            );
        }

        Schema::table('dorm_reservations', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'cancelled_by']);
        });

        DB::statement("ALTER TABLE registrations MODIFY status ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted'");
    }
};
