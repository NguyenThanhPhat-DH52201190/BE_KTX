<?php

namespace App\Console\Commands;

use App\Services\RoomFeeBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateMonthlyRoomFeeBillsCommand extends Command
{
    protected $signature = 'bills:generate-monthly
                            {--month= : Tháng cần tạo hóa đơn (mặc định: tháng hiện tại)}
                            {--year=  : Năm cần tạo hóa đơn (mặc định: năm hiện tại)}
                            {--dry-run : Chạy thử, không ghi vào database}';

    protected $description = 'Tạo hóa đơn tiền phòng tháng hiện tại cho tất cả sinh viên đang ở (ACTIVE)';

    public function __construct(private RoomFeeBillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now   = Carbon::now();
        $month = (int) ($this->option('month') ?: $now->month);
        $year  = (int) ($this->option('year') ?: $now->year);
        $isDry = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '[bills:generate-monthly] Tạo hóa đơn tháng %02d/%d%s',
            $month,
            $year,
            $isDry ? ' [DRY RUN]' : ''
        ));

        if ($isDry) {
            $this->warn('Chế độ dry-run: không có dữ liệu nào được ghi.');
            return self::SUCCESS;
        }

        $result = $this->billingService->generateMonthlyBills($month, $year);

        $this->info("Đã tạo  : {$result['generated']} hóa đơn.");
        $this->line("Bỏ qua  : {$result['skipped']} occupancy (đã có hóa đơn / chưa đến tháng / tháng check-in).");

        return self::SUCCESS;
    }
}
