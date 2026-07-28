<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('violations')) {
            return;
        }

        // Migration này chỉ backfill dữ liệu lịch sử + thêm FK cho DB MySQL thật đã tồn tại
        // sẵn student_id/room_id trên violations — không có ý nghĩa gì trên DB test SQLite
        // tạo mới hoàn toàn (bảng luôn rỗng). Dùng raw SQL kiểu MySQL (UPDATE...INNER JOIN,
        // information_schema.KEY_COLUMN_USAGE) không chạy được trên SQLite, khiến toàn bộ
        // test suite fail ngay ở bước migrate (báo cáo 28/07). Bỏ qua an toàn trên non-MySQL,
        // không ảnh hưởng gì tới hành vi migration thật trên MySQL.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $hasStudentId = Schema::hasColumn('violations', 'student_id');
        $hasRoomId = Schema::hasColumn('violations', 'room_id');
        $hasOccupancyId = Schema::hasColumn('violations', 'occupancy_id');

        if ($hasStudentId && $hasRoomId) {
            if (!$hasOccupancyId) {
                Schema::table('violations', function (Blueprint $table) {
                    $table->foreignId('occupancy_id')->nullable()->after('student_id');
                });
            }

            $missingMappings = DB::table('violations')
                ->leftJoin('occupancy', function ($join) {
                    $join->on('violations.student_id', '=', 'occupancy.student_id')
                        ->on('violations.room_id', '=', 'occupancy.room_id');
                })
                ->whereNull('occupancy.id')
                ->count();

            if ($missingMappings > 0) {
                throw new \RuntimeException('Cannot migrate violations to occupancy_id because some rows do not match an occupancy record.');
            }

            DB::statement('UPDATE violations v INNER JOIN occupancy o ON v.student_id = o.student_id AND v.room_id = o.room_id SET v.occupancy_id = o.id');

            Schema::table('violations', function (Blueprint $table) {
                if (Schema::hasColumn('violations', 'student_id')) {
                    $table->dropConstrainedForeignId('student_id');
                }

                if (Schema::hasColumn('violations', 'room_id')) {
                    $table->dropConstrainedForeignId('room_id');
                }
            });
        }

        if (Schema::hasColumn('violations', 'occupancy_id') && !$this->violationsHasForeignKey('occupancy_id')) {
            Schema::table('violations', function (Blueprint $table) {
                $table->foreign('occupancy_id')->references('id')->on('occupancy')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('violations')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('violations', function (Blueprint $table) {
            if (!Schema::hasColumn('violations', 'student_id')) {
                $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            }

            if (!Schema::hasColumn('violations', 'room_id')) {
                $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            }
        });

        DB::statement('UPDATE violations v INNER JOIN occupancy o ON v.occupancy_id = o.id SET v.student_id = o.student_id, v.room_id = o.room_id');

        Schema::table('violations', function (Blueprint $table) {
            if (Schema::hasColumn('violations', 'occupancy_id')) {
                $table->dropConstrainedForeignId('occupancy_id');
            }
        });
    }

    private function violationsHasForeignKey(string $column): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'violations')
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};