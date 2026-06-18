<?php

namespace App\Console\Commands;

use App\Services\RoomFeeBillingService;
use Illuminate\Console\Command;

class UpdateOverdueBillsCommand extends Command
{
    protected $signature = 'bills:update-overdue';

    protected $description = 'Chuyển hóa đơn tiền phòng unpaid đã quá hạn (due_date < hôm nay) sang trạng thái overdue';

    public function __construct(private RoomFeeBillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->billingService->markOverdueBills();

        $this->info("[bills:update-overdue] Đã cập nhật {$count} hóa đơn sang overdue.");

        return self::SUCCESS;
    }
}
