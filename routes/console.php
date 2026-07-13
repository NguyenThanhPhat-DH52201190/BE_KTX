<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('periods:update-status')->dailyAt('00:00');

// Tạo hóa đơn tiền phòng theo quý (gộp 3 tháng) cho tất cả sinh viên ACTIVE —
// chạy đúng ngày 01 đầu mỗi quý (T1, T4, T7, T10) lúc 07:00.
Schedule::command('bills:generate-quarterly')->cron('0 7 1 1,4,7,10 *');

// Đánh dấu hóa đơn quá hạn (unpaid -> overdue) mỗi ngày lúc 01:00
Schedule::command('bills:update-overdue')->dailyAt('01:00');

// Xử lý hóa đơn overdue: nhắc nợ, buộc thôi ở, blacklist (chạy SAU update-overdue)
Schedule::command('bills:process-overdue')->dailyAt('02:00')->withoutOverlapping();

// Tự động tạo bản nháp đợt gia hạn khi phát hiện lưu trú sắp hết hạn trong 45 ngày (chạy lúc 08:00)
Schedule::command('periods:auto-draft')->dailyAt('08:00');

// Kết thúc tự động lưu trú hết hạn: occupancy → COMPLETED, giường → EMPTY (chạy lúc 00:30)
Schedule::command('occupancies:expire')->dailyAt('00:30')->withoutOverlapping();

// Gửi nhắc nhở gia hạn lưu trú: 30 ngày và 7 ngày trước khi hết hạn (chạy lúc 09:00)
Schedule::command('extensions:send-reminders')->dailyAt('09:00')->withoutOverlapping();

// Nhắc sinh viên chọn giường trước 1 ngày khi hết hạn (chạy lúc 08:30)
Schedule::command('bed-selection:send-reminders')->dailyAt('08:30')->withoutOverlapping();

// Huỷ hồ sơ đã phân phòng nhưng quá hạn chọn giường: không tự gán giường,
// sinh viên phải đăng ký lại nếu vẫn muốn ở KTX (chạy lúc 00:20)
Schedule::command('bed-selection:expire')->dailyAt('00:20')->withoutOverlapping();

// Huỷ giữ chỗ của sinh viên đã chọn giường nhưng quá hạn đóng tiền lần đầu
// (initial_payment_due_days): giải phóng giường, sinh viên phải đăng ký lại (chạy lúc 00:25)
Schedule::command('initial-payment:expire')->dailyAt('00:25')->withoutOverlapping();

// Tự động bắt đầu bảo trì phòng đã đến ngày started_at (chạy lúc 05:30)
Schedule::command('maintenance:auto-start')->dailyAt('05:30')->withoutOverlapping();

// Tự động hoàn tất bảo trì đã đến ngày dự kiến kết thúc (chạy lúc 00:05)
Schedule::command('maintenance:auto-complete')->dailyAt('00:05')->withoutOverlapping();

// Gửi thông báo hệ thống đã hẹn giờ.
Schedule::command('system-announcements:send-scheduled')->everyMinute()->withoutOverlapping();

// Đồng bộ lại AWS Rekognition Collection: dọn face trôi dạt/mồ côi, index lại đúng
// sinh viên có bản ghi lưu trú hợp lệ (chạy hàng tuần, giờ thấp điểm)
Schedule::command('rekognition:resync --force')->weeklyOn(0, '03:00')->withoutOverlapping();
