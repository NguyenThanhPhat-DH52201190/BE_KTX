<?php

namespace App\Console\Commands;

use App\Services\RoomFeeBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateQuarterlyRoomFeeBillsCommand extends Command
{
    private const QUARTER_START_MONTHS = [1, 4, 7, 10];

    protected $signature = 'bills:generate-quarterly
                            {--month= : Tháng đầu quý cần tạo hóa đơn (mặc định: tháng hiện tại) — phải là 1, 4, 7 hoặc 10}
                            {--year=  : Năm cần tạo hóa đơn (mặc định: năm hiện tại)}
                            {--dry-run : Chạy thử, không ghi vào database}';

    protected $description = 'Tạo hóa đơn tiền phòng theo quý (gộp 3 tháng) cho tất cả sinh viên đang ở (ACTIVE) — chạy vào đầu mỗi quý (01/01, 01/04, 01/07, 01/10)';

    public function __construct(private RoomFeeBillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now         = Carbon::now();
        $monthOption = $this->option('month');
        $month       = (int) ($monthOption ?: $now->month);
        $year        = (int) ($this->option('year') ?: $now->year);
        $isDry       = (bool) $this->option('dry-run');

        // Guard: chặn chạy nhầm vào tháng không phải đầu quý, trừ khi người
        // chạy tay tường minh truyền --month (biết rõ mình đang làm gì, ví dụ
        // chạy bù cho 1 quý cũ).
        if (! in_array($month, self::QUARTER_START_MONTHS, true) && $monthOption === null) {
            $this->error(sprintf(
                'Tháng %02d không phải đầu quý (1, 4, 7, 10). Lệnh này chỉ tự chạy vào đầu quý — '
                    . 'nếu muốn tạo bù cho 1 quý cụ thể, truyền rõ --month=<1|4|7|10> --year=<năm>.',
                $month
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '[bills:generate-quarterly] Tạo hóa đơn quý bắt đầu từ tháng %02d/%d%s',
            $month,
            $year,
            $isDry ? ' [DRY RUN]' : ''
        ));

        if ($isDry) {
            $this->warn('Chế độ dry-run: không có dữ liệu nào được ghi.');
            return self::SUCCESS;
        }

        $result = $this->billingService->generateQuarterlyBills($month, $year);

        $this->info("Đã tạo  : {$result['generated']} hóa đơn.");
        $this->line("Bỏ qua  : {$result['skipped']} occupancy (đã có hóa đơn / chưa đến quý / chưa vào ở).");

        return self::SUCCESS;
    }
}
