<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đổi room_fee_bills.registration_id -> occupancy_id.
     * Hóa đơn tiền phòng thuộc về kỳ lưu trú (occupancy), không phải đơn đăng ký.
     */
    public function up(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        // 1. Thêm cột occupancy_id (nullable) sau student_id.
        if (! Schema::hasColumn('room_fee_bills', 'occupancy_id')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->unsignedBigInteger('occupancy_id')->nullable()->after('student_id');
            });
        }

        // 2. Migrate dữ liệu cũ: registration_id -> occupancy_id (qua occupancy.registration_id).
        $migrated = 0;
        if (Schema::hasColumn('room_fee_bills', 'registration_id') && Schema::hasTable('occupancy')) {
            DB::statement('
                UPDATE room_fee_bills r
                SET r.occupancy_id = (
                    SELECT o.id FROM occupancy o
                    WHERE o.registration_id = r.registration_id
                    ORDER BY o.id ASC
                    LIMIT 1
                )
                WHERE r.registration_id IS NOT NULL
            ');

            $migrated = (int) DB::table('room_fee_bills')->whereNotNull('occupancy_id')->count();
        }
        echo "  -> Đã migrate occupancy_id cho {$migrated} dòng room_fee_bills." . PHP_EOL;

        // 3. Xóa cột registration_id (kèm FK của nó).
        if (Schema::hasColumn('room_fee_bills', 'registration_id')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                // Cột tạo bằng foreignId()->constrained() nên có FK đi kèm.
                try {
                    $table->dropForeign(['registration_id']);
                } catch (\Throwable $e) {
                    // FK có thể không tồn tại (đã bị gỡ trước đó) -> bỏ qua.
                }
                $table->dropColumn('registration_id');
            });
        }

        // 4. Thêm FK occupancy_id -> occupancy(id) ON DELETE SET NULL.
        if (! $this->foreignKeyExists('room_fee_bills', 'room_fee_bills_occupancy_id_foreign')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->foreign('occupancy_id', 'room_fee_bills_occupancy_id_foreign')
                    ->references('id')
                    ->on('occupancy')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_fee_bills')) {
            return;
        }

        // 1. Thêm lại cột registration_id (nullable để chứa dữ liệu suy ngược).
        if (! Schema::hasColumn('room_fee_bills', 'registration_id')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->unsignedBigInteger('registration_id')->nullable()->after('student_id');
            });
        }

        // 2. Suy ngược registration_id từ occupancy.registration_id.
        if (Schema::hasColumn('room_fee_bills', 'occupancy_id') && Schema::hasTable('occupancy')) {
            DB::statement('
                UPDATE room_fee_bills r
                JOIN occupancy o ON o.id = r.occupancy_id
                SET r.registration_id = o.registration_id
                WHERE r.occupancy_id IS NOT NULL
            ');
        }

        // 3. Gỡ FK + cột occupancy_id.
        if ($this->foreignKeyExists('room_fee_bills', 'room_fee_bills_occupancy_id_foreign')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->dropForeign('room_fee_bills_occupancy_id_foreign');
            });
        }
        if (Schema::hasColumn('room_fee_bills', 'occupancy_id')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->dropColumn('occupancy_id');
            });
        }

        // 4. Khôi phục FK registration_id -> registrations(id) như ban đầu.
        if (Schema::hasTable('registrations')
            && ! $this->foreignKeyExists('room_fee_bills', 'room_fee_bills_registration_id_foreign')) {
            Schema::table('room_fee_bills', function (Blueprint $table) {
                $table->foreign('registration_id', 'room_fee_bills_registration_id_foreign')
                    ->references('id')
                    ->on('registrations')
                    ->cascadeOnDelete();
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(*) AS total
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            [$table, $constraint]
        );

        return $result && (int) $result->total > 0;
    }
};
