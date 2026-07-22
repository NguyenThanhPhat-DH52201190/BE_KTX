<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueStatusCommand extends Command
{
    protected $signature   = 'queue:status';
    protected $description = 'Hiện số job đang chờ và số job đã lỗi trong hàng đợi (bảng jobs/failed_jobs)';

    public function handle(): int
    {
        if (!Schema::hasTable('jobs') || !Schema::hasTable('failed_jobs')) {
            $this->error('Chưa có bảng jobs/failed_jobs — chạy "php artisan migrate" trước.');
            return self::FAILURE;
        }

        $pending = DB::table('jobs')->count();
        $failed  = DB::table('failed_jobs')->count();

        $oldestPendingAge = null;
        if ($pending > 0) {
            $oldestAvailableAt = DB::table('jobs')->min('available_at');
            $oldestPendingAge  = now()->diffForHumans(now()->setTimestamp($oldestAvailableAt), true);
        }

        $this->info("Job đang chờ xử lý: {$pending}");
        if ($oldestPendingAge) {
            $this->line("  → Job cũ nhất đã chờ khoảng: {$oldestPendingAge}");
        }
        $this->info("Job đã lỗi (hết retry): {$failed}");

        if ($pending > 20) {
            $this->warn('Hàng đợi đang tồn đọng khá nhiều — kiểm tra queue worker (Windows Service) có đang chạy không.');
        }
        if ($failed > 0) {
            $this->warn('Có job lỗi — xem chi tiết bằng: php artisan queue:failed');
        }

        return self::SUCCESS;
    }
}
