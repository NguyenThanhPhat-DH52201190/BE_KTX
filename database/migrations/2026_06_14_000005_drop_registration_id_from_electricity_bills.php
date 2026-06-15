<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Xóa electricity_bills.registration_id (thừa) — hóa đơn điện gắn occupancy_id.
     * Backfill occupancy_id trước khi drop để không mất liên kết.
     */
    public function up(): void
    {
        if (! Schema::hasTable('electricity_bills')) {
            return;
        }

        // 1. Backfill occupancy_id cho các dòng còn NULL (qua occupancy.registration_id).
        if (Schema::hasColumn('electricity_bills', 'registration_id')
            && Schema::hasColumn('electricity_bills', 'occupancy_id')
            && Schema::hasTable('occupancy')) {
            DB::statement('
                UPDATE electricity_bills b
                SET b.occupancy_id = (
                    SELECT o.id FROM occupancy o
                    WHERE o.registration_id = b.registration_id
                    ORDER BY o.id ASC
                    LIMIT 1
                )
                WHERE b.occupancy_id IS NULL AND b.registration_id IS NOT NULL
            ');
            $backfilled = (int) DB::table('electricity_bills')->whereNotNull('occupancy_id')->count();
            echo "  -> occupancy_id đã có giá trị cho {$backfilled} dòng electricity_bills." . PHP_EOL;
        }

        // 2. Drop FK + cột registration_id.
        if (Schema::hasColumn('electricity_bills', 'registration_id')) {
            Schema::table('electricity_bills', function (Blueprint $table) {
                try {
                    $table->dropForeign('electricity_bills_registration_id_foreign');
                } catch (\Throwable $e) {
                    // FK có thể đã bị gỡ trước đó.
                }
                $table->dropColumn('registration_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('electricity_bills')) {
            return;
        }

        // 1. Thêm lại cột registration_id (nullable để chứa dữ liệu suy ngược).
        if (! Schema::hasColumn('electricity_bills', 'registration_id')) {
            Schema::table('electricity_bills', function (Blueprint $table) {
                $table->unsignedBigInteger('registration_id')->nullable()->after('student_id');
            });
        }

        // 2. Suy ngược registration_id từ occupancy.registration_id.
        if (Schema::hasColumn('electricity_bills', 'occupancy_id') && Schema::hasTable('occupancy')) {
            DB::statement('
                UPDATE electricity_bills b
                JOIN occupancy o ON o.id = b.occupancy_id
                SET b.registration_id = o.registration_id
                WHERE b.occupancy_id IS NOT NULL
            ');
        }

        // 3. Khôi phục FK registration_id -> registrations(id).
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = "electricity_bills"
               AND CONSTRAINT_NAME = "electricity_bills_registration_id_foreign"
               AND CONSTRAINT_TYPE = "FOREIGN KEY"'
        );
        if (Schema::hasTable('registrations') && (! $exists || (int) $exists->total === 0)) {
            Schema::table('electricity_bills', function (Blueprint $table) {
                $table->foreign('registration_id', 'electricity_bills_registration_id_foreign')
                    ->references('id')
                    ->on('registrations')
                    ->cascadeOnDelete();
            });
        }
    }
};
