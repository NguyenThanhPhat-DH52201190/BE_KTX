<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetTestDataCommand extends Command
{
    protected $signature = 'db:reset-test-data
                            {--force : Bỏ qua xác nhận, chạy thẳng}';

    protected $description = 'Xóa toàn bộ dữ liệu động trước go-live, giữ nguyên cấu trúc bảng và data nền (buildings, floors, rooms, beds, students, admin, settings, provinces, activity_types, priority_criteria, fee_discount_policies, static_pages)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->warn('⚠  Lệnh này sẽ XÓA SẠCH toàn bộ dữ liệu động trong database (đăng ký, lưu trú, hóa đơn, thông báo, vi phạm...)!');
            $this->warn('   Giữ lại: buildings, floors, rooms, beds, students, accounts (admin), settings, provinces, activity_types, priority_criteria, fee_discount_policies, static_pages.');
            if (! $this->confirm('Bạn có chắc muốn tiếp tục không?')) {
                $this->info('Đã hủy.');
                return self::SUCCESS;
            }
        }

        $this->info('Bắt đầu reset data...');
        $this->newLine();

        $report = [];
        $fileDeleteSummary = [];

        // Thu thập + xóa file vật lý (avatar/CCCD/minh chứng ưu tiên/đính kèm) TRƯỚC khi xóa dòng DB
        // tương ứng, tránh để lại file mồ côi trên storage/app/public sau khi dữ liệu đã sạch.
        $this->info('Đang thu thập file vật lý cần xóa...');
        $filePaths = array_unique(array_merge(
            $this->collectFilePaths('registrations', ['avatar_url', 'cccd_front_url', 'cccd_back_url']),
            $this->collectFilePaths('dorm_reservations', ['avatar_url', 'cccd_front_url', 'cccd_back_url']),
            $this->collectFilePaths('student_priority_evidence', ['file_url']),
            $this->collectFilePaths('reservation_priority_evidences', ['file_url']),
            $this->collectFilePaths('student_support_requests', ['attachment_url']),
        ));

        if (count($filePaths) > 0) {
            $this->line('Danh sách file sẽ xóa (' . count($filePaths) . ' file):');
            foreach ($filePaths as $path) {
                $this->line('  - ' . $path);
            }
            $deletedCount = 0;
            foreach ($filePaths as $path) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                    $deletedCount++;
                }
            }
            $fileDeleteSummary = ['table' => 'file vật lý (avatar/CCCD/minh chứng/đính kèm)', 'deleted' => $deletedCount, 'skipped' => false];
        } else {
            $fileDeleteSummary = ['table' => 'file vật lý (avatar/CCCD/minh chứng/đính kèm)', 'deleted' => 0, 'skipped' => false];
        }
        $this->newLine();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Thứ tự xóa dưới đây theo đúng phụ thuộc khóa ngoại (bảng con trước, bảng cha sau) —
            // dù FOREIGN_KEY_CHECKS đã tắt nên thứ tự không bắt buộc, vẫn giữ đúng thứ tự để an toàn
            // nếu sau này bỏ FOREIGN_KEY_CHECKS=0 hoặc chạy lại từng phần thủ công.

            // 1. student_priority_evidence (con của student_priority)
            $report[] = $this->truncateIfExists('student_priority_evidence');

            // 2. reservation_priority_evidences (con của reservation_priorities)
            $report[] = $this->truncateIfExists('reservation_priority_evidences');

            // 3. student_payment_plans (con của student_support_requests)
            $report[] = $this->truncateIfExists('student_payment_plans');

            // 4. notification_recipient (con của notifications)
            $report[] = $this->truncateIfExists('notification_recipient');

            // 5. electricity_bills (con của electricity_records + occupancy)
            $report[] = $this->truncateIfExists('electricity_bills');

            // 6. room_change_requests (con của occupancy — bảng legacy, thường rỗng nhưng vẫn dọn để an toàn)
            $report[] = $this->truncateIfExists('room_change_requests');

            // 7. room_change_log (con của maintenance_requests + occupancy)
            $report[] = $this->truncateIfExists('room_change_log');

            // 8. checkout_requests (con của occupancy)
            $report[] = $this->truncateIfExists('checkout_requests');

            // 9. activities (con của occupancy)
            $report[] = $this->truncateIfExists('activities');

            // 10. room_fee_bills (con của occupancy)
            $report[] = $this->truncateTable('room_fee_bills');

            // 11. occupancy_extensions (con của occupancy + occupancy_periods)
            $report[] = $this->truncateIfExists('occupancy_extensions');

            // 12. reservation_priorities (con của dorm_reservations)
            $report[] = $this->truncateIfExists('reservation_priorities');

            // 13. student_priority (con của registrations)
            $report[] = $this->truncateIfExists('student_priority');

            // 14. waitlist (con của registrations + registration_periods)
            $report[] = $this->truncateIfExists('waitlist');

            // 15. dorm_reservations (con của admission_candidates + registrations + registration_periods)
            $report[] = $this->truncateIfExists('dorm_reservations');

            // 16. occupancy (con của registrations)
            $report[] = $this->truncateTable('occupancy');

            // 17. registrations (con của registration_periods)
            $report[] = $this->truncateTable('registrations');

            // 18. student_support_requests (cha của student_payment_plans, đã xóa ở bước 3)
            $report[] = $this->truncateIfExists('student_support_requests');

            // 19. maintenance_requests (cha của room_change_log, đã xóa ở bước 7)
            $report[] = $this->truncateIfExists('maintenance_requests');

            // 20. notifications (cha của notification_recipient, đã xóa ở bước 4)
            $report[] = $this->truncateIfExists('notifications');

            // 21. electricity_records (cha của electricity_bills, đã xóa ở bước 5)
            $report[] = $this->truncateIfExists('electricity_records');

            // 22. admission_candidates (cha của dorm_reservations, đã xóa ở bước 15)
            $report[] = $this->truncateIfExists('admission_candidates');

            // 23. registration_periods (cha của registrations/waitlist/dorm_reservations, đã xóa ở trên)
            $report[] = $this->truncateTable('registration_periods');

            // 24. occupancy_periods (cha của occupancy_extensions, đã xóa ở bước 11)
            $report[] = $this->truncateIfExists('occupancy_periods');

            // 25. blacklist (độc lập, chỉ tham chiếu students/accounts được giữ nguyên)
            $report[] = $this->truncateIfExists('blacklist');

            // 26. payment_reminders (độc lập, chỉ tham chiếu students được giữ nguyên)
            $report[] = $this->truncateIfExists('payment_reminders');

            // 27. admin_notifications (độc lập)
            $report[] = $this->truncateIfExists('admin_notifications');

            // 28. Xóa accounts có role = 'student' (giữ nguyên accounts role=admin)
            $studentAccountCount = DB::table('accounts')->where('role', 'student')->count();
            DB::table('accounts')->where('role', 'student')->delete();
            DB::statement('ALTER TABLE accounts AUTO_INCREMENT = 1');
            $report[] = ['table' => 'accounts (role=student)', 'deleted' => $studentAccountCount, 'skipped' => false];

            // 29. personal_access_tokens (token đăng nhập cũ — xóa hết để không còn phiên đăng nhập test)
            $report[] = $this->truncateIfExists('personal_access_tokens');

            // 30. sessions
            $report[] = $this->truncateIfExists('sessions');

            // 31-34. jobs / failed_jobs / cache / cache_locks
            $report[] = $this->truncateIfExists('jobs');
            $report[] = $this->truncateIfExists('failed_jobs');
            $report[] = $this->truncateIfExists('cache');
            $report[] = $this->truncateIfExists('cache_locks');

            // 35. Reset beds.status về 'active'
            $bedsReset = DB::table('beds')->update(['status' => 'active']);
            if ($this->columnExists('beds', 'physical_status')) {
                DB::table('beds')->update(['physical_status' => 'active']);
            }
            $report[] = ['table' => 'beds (reset status=active)', 'deleted' => $bedsReset, 'skipped' => false];

            // 36. Reset rooms.status về 'active'
            $roomsReset = DB::table('rooms')->update(['status' => 'active']);
            $report[] = ['table' => 'rooms (reset status=active)', 'deleted' => $roomsReset, 'skipped' => false];

        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Báo cáo
        $this->newLine();
        $this->info('=== KẾT QUẢ ===');
        $rows = [];
        foreach (array_merge([$fileDeleteSummary], $report) as $r) {
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

    /**
     * Lấy toàn bộ giá trị không rỗng của các cột lưu đường dẫn file (avatar/CCCD/minh chứng...)
     * trong 1 bảng, quy đổi về path tương đối trên disk 'public' (storage/app/public/...).
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function collectFilePaths(string $table, array $columns): array
    {
        if (! $this->tableExists($table)) {
            return [];
        }

        $paths = [];
        foreach (DB::table($table)->get($columns) as $row) {
            foreach ($columns as $column) {
                $value = $row->{$column} ?? null;
                if (! empty($value)) {
                    $paths[] = $this->normalizeStoragePath($value);
                }
            }
        }

        return $paths;
    }

    /**
     * Một số bản ghi lưu URL đầy đủ dạng "/api/storage/xxx" hoặc "/storage/xxx" thay vì
     * path tương đối thuần — cắt bỏ tiền tố để ra đúng path dùng được với Storage::disk('public').
     */
    private function normalizeStoragePath(string $value): string
    {
        $value = ltrim($value, '/');

        foreach (['api/storage/', 'storage/'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return substr($value, strlen($prefix));
            }
        }

        return $value;
    }
}
