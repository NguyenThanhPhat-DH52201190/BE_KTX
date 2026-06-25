<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetTestDataCommand extends Command
{
    protected $signature = 'db:reset-test-data
                            {--force : Bỏ qua xác nhận, chạy thẳng}';

    protected $description = 'Xóa toàn bộ data test, giữ nguyên cấu trúc bảng và data nền (buildings, floors, rooms, beds, students, admin, settings)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->warn('⚠  Lệnh này sẽ XÓA SẠCH data test trong database!');
            $this->warn('   Giữ lại: buildings, floors, rooms, beds, students, accounts (admin), settings.');
            if (! $this->confirm('Bạn có chắc muốn tiếp tục không?')) {
                $this->info('Đã hủy.');
                return self::SUCCESS;
            }
        }

        $this->info('Bắt đầu reset data test...');
        $this->newLine();

        $report = [];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // 1. room_change_log
            $report[] = $this->truncateTable('room_change_log');

            // 2. room_fee_bills
            $report[] = $this->truncateTable('room_fee_bills');

            // 3. electricity_bills
            $report[] = $this->truncateIfExists('electricity_bills');

            // 4. electricity_records
            $report[] = $this->truncateIfExists('electricity_records');

            // 5. payment_reminders
            $report[] = $this->truncateIfExists('payment_reminders');

            // 6. notifications + recipient mapping
            $report[] = $this->truncateIfExists('notification_recipient');
            $report[] = $this->truncateIfExists('notifications');
            $report[] = $this->truncateIfExists('admin_notifications');

            // 7. activities
            $report[] = $this->truncateIfExists('activities');

            // 8. checkout_requests
            $report[] = $this->truncateIfExists('checkout_requests');

            // 9. room_change_requests
            $report[] = $this->truncateIfExists('room_change_requests');

            // 10. student_support_requests
            $report[] = $this->truncateIfExists('student_support_requests');

            // 11. maintenance_requests
            $report[] = $this->truncateIfExists('maintenance_requests');

            // 12. student_priority_evidence
            $report[] = $this->truncateIfExists('student_priority_evidence');

            // 13. student_priority
            $report[] = $this->truncateIfExists('student_priority');

            // 14. waitlist
            $report[] = $this->truncateIfExists('waitlist');

            // 15. blacklist
            $report[] = $this->truncateIfExists('blacklist');

            // 16. occupancy_extensions
            $report[] = $this->truncateIfExists('occupancy_extensions');

            // 17. occupancy_periods
            $report[] = $this->truncateIfExists('occupancy_periods');

            // 18. occupancy
            $report[] = $this->truncateTable('occupancy');

            // 19. registrations
            $report[] = $this->truncateTable('registrations');

            // 20. registration_periods
            $report[] = $this->truncateTable('registration_periods');

            // 21. Xóa accounts có role = 'student'
            $studentAccountCount = DB::table('accounts')->where('role', 'student')->count();
            DB::table('accounts')->where('role', 'student')->delete();
            DB::statement('ALTER TABLE accounts AUTO_INCREMENT = 1');
            $report[] = ['table' => 'accounts (role=student)', 'deleted' => $studentAccountCount, 'skipped' => false];

            // 22. sessions
            $report[] = $this->truncateIfExists('sessions');

            // 23. Reset beds.status về 'active'
            $bedsReset = DB::table('beds')->update(['status' => 'active']);
            if ($this->columnExists('beds', 'physical_status')) {
                DB::table('beds')->update(['physical_status' => 'active']);
            }
            $report[] = ['table' => 'beds (reset status=active)', 'deleted' => $bedsReset, 'skipped' => false];

            // 24. Reset rooms.status về 'active'
            $roomsReset = DB::table('rooms')->update(['status' => 'active']);
            $report[] = ['table' => 'rooms (reset status=active)', 'deleted' => $roomsReset, 'skipped' => false];

        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Báo cáo
        $this->newLine();
        $this->info('=== KẾT QUẢ ===');
        $rows = [];
        foreach ($report as $r) {
            if ($r === null) {
                continue;
            }
            $rows[] = [
                $r['table'],
                $r['skipped'] ? '-' : number_format($r['deleted']),
                $r['skipped'] ? 'bảng không tồn tại, bỏ qua' : 'OK',
            ];
        }
        $this->table(['Bảng', 'Số record', 'Trạng thái'], $rows);
        $this->newLine();
        $this->info('✅ Reset xong! Data nền (tòa/tầng/phòng/giường/sinh viên/admin/settings) được giữ nguyên.');

        return self::SUCCESS;
    }

    private function truncateTable(string $table): array
    {
        $count = DB::table($table)->count();
        DB::statement("TRUNCATE TABLE `{$table}`");
        return ['table' => $table, 'deleted' => $count, 'skipped' => false];
    }

    private function truncateIfExists(string $table): array
    {
        if (! $this->tableExists($table)) {
            return ['table' => $table, 'deleted' => 0, 'skipped' => true];
        }
        return $this->truncateTable($table);
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }
}
