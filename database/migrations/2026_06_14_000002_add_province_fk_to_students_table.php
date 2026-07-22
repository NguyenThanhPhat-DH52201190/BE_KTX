<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Thêm khóa ngoại students.province_code -> provinces.code.
     * ON DELETE SET NULL: xóa tỉnh thì sinh viên về NULL (chờ admin gán lại).
     *
     * Nếu dữ liệu hiện có chứa province_code không khớp provinces.code,
     * việc thêm FK sẽ thất bại; khi đó migration sẽ bỏ qua FK và ghi log
     * để admin chạy `php artisan provinces:map-students` rồi thêm FK sau.
     */
    public function up(): void
    {
        if (! Schema::hasTable('students') || ! Schema::hasTable('provinces')) {
            return;
        }

        if (! Schema::hasColumn('students', 'province_code')) {
            return;
        }

        if ($this->foreignKeyExists()) {
            return;
        }

        try {
            // Dọn các giá trị mồ côi (không khớp tỉnh nào) về NULL trước khi gắn FK.
            DB::statement('
                UPDATE students s
                LEFT JOIN provinces p ON p.code = s.province_code
                SET s.province_code = NULL
                WHERE s.province_code IS NOT NULL AND p.code IS NULL
            ');

            Schema::table('students', function (Blueprint $table) {
                $table->foreign('province_code', 'students_province_code_foreign')
                    ->references('code')
                    ->on('provinces')
                    ->nullOnDelete();
            });
        } catch (\Throwable $e) {
            Log::warning('Bỏ qua FK students.province_code: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('students')) {
            return;
        }

        if (! $this->foreignKeyExists()) {
            return;
        }

        try {
            Schema::table('students', function (Blueprint $table) {
                $table->dropForeign('students_province_code_foreign');
            });
        } catch (\Throwable $e) {
            Log::warning('Bỏ qua drop FK students.province_code: ' . $e->getMessage());
        }
    }

    private function foreignKeyExists(): bool
    {
        $database = DB::getDatabaseName();

        $result = DB::selectOne(
            'SELECT COUNT(*) AS total
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            [$database, 'students', 'students_province_code_foreign']
        );

        return $result && (int) $result->total > 0;
    }
};
