<?php

namespace App\Console\Commands;

use App\Models\OccupancyPeriod;
use Illuminate\Console\Command;

class AutoCloseOccupancyPeriodCommand extends Command
{
    protected $signature   = 'occupancy-periods:auto-close {--dry-run : Chạy thử, không ghi database}';
    protected $description = 'Tự động đóng đợt gia hạn lưu trú đã quá hạn nhận đơn (17:00 của end_date)';

    public function handle(): int
    {
        $isDry = (bool) $this->option('dry-run');
        $now   = now();

        $periods = OccupancyPeriod::where('status', 'open')->get()
            ->filter(fn (OccupancyPeriod $period) => $period->applicationDeadline()?->isPast() ?? false);

        if ($periods->isEmpty()) {
            $this->info('[occupancy-periods:auto-close] Không có đợt nào quá hạn.');
            return self::SUCCESS;
        }

        foreach ($periods as $period) {
            $deadline = $period->applicationDeadline();

            if ($isDry) {
                $this->line("  [DRY] \"{$period->name}\" — quá hạn từ {$deadline->format('H:i d/m/Y')}, sẽ đóng.");
                continue;
            }

            $period->update(['status' => 'closed']);
            $this->line("  [CLOSED] \"{$period->name}\" — quá hạn từ {$deadline->format('H:i d/m/Y')}.");
        }

        $this->info(sprintf('[occupancy-periods:auto-close] %s %d đợt.', $isDry ? 'Sẽ đóng' : 'Đã đóng', $periods->count()));

        return self::SUCCESS;
    }
}
