<?php

namespace App\Console\Commands;

use App\Models\Occupancy;
use App\Models\OccupancyPeriod;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoDraftExtensionPeriodCommand extends Command
{
    protected $signature   = 'periods:auto-draft
                                {--days=45 : Số ngày trước hết hạn để kích hoạt tạo bản nháp}
                                {--dry-run : Chạy thử, không ghi database}';
    protected $description = 'Tự động tạo bản nháp đợt gia hạn khi có sinh viên sắp hết hạn lưu trú';

    public function handle(): int
    {
        $isDry  = (bool) $this->option('dry-run');
        $days   = (int)  $this->option('days');
        $today  = Carbon::today();
        $window = $today->copy()->addDays($days)->toDateString();

        $this->info(sprintf(
            '[periods:auto-draft] %s — kiểm tra occupancy hết hạn trong vòng %d ngày (đến %s)',
            $isDry ? 'DRY RUN' : 'Bắt đầu...',
            $days,
            $window,
        ));

        // Nếu đã có đợt đang mở hoặc bản nháp thì không cần tạo mới
        $existing = OccupancyPeriod::whereIn('status', ['open', 'draft'])->first();
        if ($existing) {
            $this->info("Đã có đợt \"{$existing->name}\" (trạng thái: {$existing->status}). Bỏ qua.");
            return self::SUCCESS;
        }

        // Tìm tất cả ACTIVE occupancy hết hạn trong vòng $days ngày
        $expiring = Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_out_date')
            ->whereDate('check_out_date', '>=', $today)
            ->whereDate('check_out_date', '<=', $window)
            ->orderBy('check_out_date')
            ->get();

        if ($expiring->isEmpty()) {
            $this->info("Không có lưu trú nào hết hạn trong vòng {$days} ngày. Bỏ qua.");
            return self::SUCCESS;
        }

        $count    = $expiring->count();
        $earliest = $expiring->first()->check_out_date; // string yyyy-mm-dd
        $label    = Carbon::parse($earliest)->locale('vi')->isoFormat('MM/YYYY');

        $this->info("Tìm thấy {$count} lưu trú hết hạn (sớm nhất: {$earliest}).");

        if ($isDry) {
            $this->line("  [DRY RUN] Sẽ tạo bản nháp: \"Đợt gia hạn lưu trú {$label}\".");
            return self::SUCCESS;
        }

        $period = OccupancyPeriod::create([
            'name'                 => "Đợt gia hạn lưu trú {$label}",
            'start_date'           => $today->toDateString(),
            'end_date'             => $earliest,
            'extension_until_date' => null,
            'status'               => 'draft',
            'description'          => "Tạo tự động — {$count} sinh viên có lưu trú hết hạn trước ngày {$earliest}. "
                . 'Vui lòng điền "Gia hạn lưu trú đến" rồi bấm Mở đợt.',
        ]);

        $this->info("Đã tạo bản nháp #{$period->id}: \"{$period->name}\".");
        $this->warn('Admin cần điền ngày "Gia hạn lưu trú đến" và mở đợt tại trang Quản lý đợt gia hạn.');

        Log::info("[AutoDraftExtensionPeriod] Tạo bản nháp #{$period->id} ({$count} sinh viên sắp hết hạn, sớm nhất {$earliest}).");

        return self::SUCCESS;
    }
}
