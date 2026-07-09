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

        // Gom ĐÚNG KHỚP theo từng giá trị check_out_date cụ thể — không trộn nhiều ngày khác
        // nhau vào chung 1 đợt gia hạn (nếu có nhiều mốc lệch nhau trong cửa sổ $days ngày,
        // chỉ tạo bản nháp cho mốc sớm nhất; các mốc còn lại sẽ được xử lý ở lần chạy cron kế
        // tiếp sau khi đợt hiện tại đóng lại).
        $earliest = $expiring->first()->check_out_date; // string yyyy-mm-dd
        $group    = $expiring->filter(fn (Occupancy $o) => $o->check_out_date === $earliest)->values();
        $count    = $group->count();
        $label    = Carbon::parse($earliest)->locale('vi')->isoFormat('MM/YYYY');

        $this->info("Tìm thấy {$count} lưu trú hết hạn đúng ngày {$earliest} (trong tổng {$expiring->count()} lưu trú hết hạn trong vòng {$days} ngày).");

        if ($isDry) {
            $this->line("  [DRY RUN] Sẽ tạo bản nháp: \"Đợt gia hạn lưu trú {$label}\".");
            return self::SUCCESS;
        }

        $period = OccupancyPeriod::create([
            'name'                 => "Đợt gia hạn lưu trú {$label}",
            'start_date'           => $today->toDateString(),
            'end_date'             => $earliest,
            'extension_until_date' => Carbon::parse($earliest)->addYear()->toDateString(),
            'status'               => 'draft',
            'description'          => "Tạo tự động — {$count} sinh viên có lưu trú hết hạn đúng ngày {$earliest}. "
                . 'Vui lòng kiểm tra rồi bấm Mở đợt.',
        ]);

        $this->info("Đã tạo bản nháp #{$period->id}: \"{$period->name}\".");
        $this->warn('Admin cần kiểm tra và mở đợt tại trang Quản lý đợt gia hạn.');

        Log::info("[AutoDraftExtensionPeriod] Tạo bản nháp #{$period->id} ({$count} sinh viên sắp hết hạn, sớm nhất {$earliest}).");

        return self::SUCCESS;
    }
}
