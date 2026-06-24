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

// Tự động tạo bản nháp đợt gia hạn khi phát hiện lưu trú sắp hết hạn trong 45 ngày (chạy lúc 07:00)
Schedule::command('periods:auto-draft')->dailyAt('07:00');

// Kết thúc tự động lưu trú hết hạn: occupancy → COMPLETED, giường → EMPTY (chạy lúc 00:30)
Schedule::command('occupancies:expire')->dailyAt('00:30');

// Gửi nhắc nhở gia hạn lưu trú: 30 ngày và 7 ngày trước khi hết hạn (chạy lúc 08:00)
Schedule::command('extensions:send-reminders')->dailyAt('08:00');

// Tự động hoàn tất bảo trì đã đến ngày dự kiến kết thúc (chạy lúc 06:00)
Schedule::command('maintenance:auto-complete')->dailyAt('06:00');
