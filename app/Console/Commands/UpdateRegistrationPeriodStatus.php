<?php

namespace App\Console\Commands;

use App\Models\RegistrationPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class UpdateRegistrationPeriodStatus extends Command
{
    protected $signature   = 'periods:update-status';
    protected $description = 'Tự động chuyển trạng thái đợt đăng ký theo ngày (pending→active, active→closed)';

    public function handle(): int
    {
        $today = Carbon::today();

        $pendingToActive = RegistrationPeriod::where('status', 'pending')
            ->whereDate('start_date', '<=', $today)
            ->get();

        foreach ($pendingToActive as $period) {
            $period->status = 'active';
            $period->save();
            $this->info("[pending→active] id={$period->id} \"{$period->name}\"");
        }

        $activeToClose = RegistrationPeriod::where('status', 'active')
            ->whereDate('end_date', '<', $today)
            ->get();

        foreach ($activeToClose as $period) {
            $period->status = 'closed';
            $period->save();
            $this->info("[active→closed] id={$period->id} \"{$period->name}\"");
        }

        $changed = $pendingToActive->count() + $activeToClose->count();
        $this->info("Xong: đã cập nhật {$changed} đợt.");

        return self::SUCCESS;
    }
}
