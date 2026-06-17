<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicateApprovedRegistrations extends Command
{
    protected $signature = 'registrations:deduplicate-approved
                            {--dry-run : Chỉ in báo cáo, không ghi DB}';

    protected $description = 'Tìm student_id có >1 bản ghi status=approved, giữ id cao nhất, đổi các bản ghi cũ hơn sang rejected';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        // Tìm các student_id có nhiều hơn 1 bản ghi approved
        $duplicates = DB::table('registrations')
            ->select('student_id', DB::raw('COUNT(*) as cnt'), DB::raw('MAX(id) as keep_id'))
            ->where('status', 'approved')
            ->groupBy('student_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('Không tìm thấy bản ghi trùng lặp. DB sạch.');
            return self::SUCCESS;
        }

        $this->warn("Tìm thấy {$duplicates->count()} student_id có >1 đơn status='approved':");
        $this->newLine();

        $totalToReject = 0;

        foreach ($duplicates as $row) {
            // Lấy danh sách toàn bộ id approved của student này
            $allIds = DB::table('registrations')
                ->where('student_id', $row->student_id)
                ->where('status', 'approved')
                ->orderByDesc('id')
                ->pluck('id')
                ->toArray();

            $keepId    = $row->keep_id;
            $rejectIds = array_filter($allIds, fn ($id) => $id !== $keepId);

            $this->line(sprintf(
                '  student_id=%d | %d bản ghi approved | Giữ id=%d | Từ chối ids=[%s]',
                $row->student_id,
                $row->cnt,
                $keepId,
                implode(', ', $rejectIds),
            ));

            $totalToReject += count($rejectIds);

            if (!$isDryRun) {
                DB::table('registrations')
                    ->whereIn('id', $rejectIds)
                    ->update([
                        'status'           => 'rejected',
                        'rejection_reason' => 'Trùng lặp dữ liệu — tự động xử lý',
                        'updated_at'       => now(),
                    ]);
            }
        }

        $this->newLine();

        if ($isDryRun) {
            $this->warn("[DRY-RUN] Sẽ đổi {$totalToReject} bản ghi sang status='rejected'. Chạy không có --dry-run để áp dụng.");
        } else {
            $this->info("Đã đổi {$totalToReject} bản ghi sang status='rejected'.");
        }

        return self::SUCCESS;
    }
}
