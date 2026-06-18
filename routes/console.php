<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('periods:update-status')->dailyAt('00:00');

// Tạo hóa đơn tiền phòng tháng mới cho tất cả sinh viên ACTIVE (ngày 01 lúc 00:05)
Schedule::command('bills:generate-monthly')->monthlyOn(1, '00:05');

// Đánh dấu hóa đơn quá hạn (unpaid -> overdue) mỗi ngày lúc 01:00
Schedule::command('bills:update-overdue')->dailyAt('01:00');

// Xử lý hóa đơn overdue: nhắc nợ, buộc thôi ở, blacklist (chạy SAU update-overdue)
Schedule::command('bills:process-overdue')->dailyAt('02:00');
