<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chạy dày (5 phút) thay vì đúng 1 lần/ngày lúc 00:00 — chỉ đổi trạng thái đợt theo
// ngày, tự nhiên idempotent, chạy dày giúp đợt chuyển trạng thái kịp thời hơn.
Schedule::command('periods:update-status')->everyFiveMinutes();

// Đóng đợt tân sinh viên đúng lúc qua hạn 17:00 end_date: hồ sơ giữ chỗ chưa
// converted → expired, đợt → closed. Chạy dày (5 phút) vì đây là mốc giờ cụ thể
// trong ngày (17:00), không thể chờ tới lượt chạy hằng ngày như periods:update-status.
Schedule::command('registration-periods:auto-close-admission')->everyFiveMinutes()->withoutOverlapping();

// Tạo hóa đơn tiền phòng theo quý (gộp 3 tháng) cho tất cả sinh viên ACTIVE —
// chạy đúng ngày 01 đầu mỗi quý (T1, T4, T7, T10) lúc 07:00.
Schedule::command('bills:generate-quarterly')->cron('0 7 1 1,4,7,10 *');

// Đánh dấu hóa đơn quá hạn (unpaid -> overdue). Chạy dày (5 phút) thay vì 1 lần/ngày —
// chỉ chuyển trạng thái theo due_date, idempotent, chạy dày giúp phát hiện quá hạn kịp
// thời hơn thay vì phải chờ tới 01:00 hôm sau.
Schedule::command('bills:update-overdue')->everyFiveMinutes();

// Xử lý hóa đơn overdue: nhắc nợ, buộc thôi ở, blacklist. PHẢI đăng ký SAU
// bills:update-overdue (như hiện tại) để giữ đúng thứ tự chạy trong cùng 1 lượt tick.
// Đã có bảng payment_reminders chống gửi trùng từng mức nhắc nợ, buộc thôi ở là hành
// động một chiều (occupancy hết ACTIVE thì không match lại) — an toàn khi chạy dày.
Schedule::command('bills:process-overdue')->everyFiveMinutes()->withoutOverlapping();

// Tự động tạo bản nháp đợt gia hạn khi phát hiện lưu trú sắp hết hạn trong 45 ngày.
// Chạy dày (5 phút) — đã có guard "đã có đợt open/draft thì bỏ qua", an toàn.
Schedule::command('periods:auto-draft')->everyFiveMinutes();

// Tự động đóng đợt gia hạn lưu trú đúng lúc qua hạn 17:00 end_date — trước đây end_date chỉ
// mang tính hiển thị, sinh viên vẫn nộp đơn được vô thời hạn nếu admin quên bấm "Đóng đợt" tay
// (báo cáo 17/08). Chạy dày (5 phút) vì đây là mốc giờ cụ thể trong ngày, giống cách
// registration-periods:auto-close-admission đang xử lý cho đợt tuyển sinh.
Schedule::command('occupancy-periods:auto-close')->everyFiveMinutes()->withoutOverlapping();

// Kết thúc tự động lưu trú hết hạn: occupancy → COMPLETED, giường → EMPTY.
// Chạy dày (5 phút) — chuyển trạng thái một chiều, idempotent.
Schedule::command('occupancies:expire')->everyFiveMinutes()->withoutOverlapping();

// Gửi nhắc nhở gia hạn lưu trú: 30 ngày và 7 ngày trước khi hết hạn (chạy lúc 09:00)
Schedule::command('extensions:send-reminders')->dailyAt('09:00')->withoutOverlapping();

// Nhắc sinh viên chọn giường trước 1 ngày khi hết hạn (chạy lúc 08:30)
Schedule::command('bed-selection:send-reminders')->dailyAt('08:30')->withoutOverlapping();

// Huỷ hồ sơ đã phân phòng nhưng quá hạn chọn giường: không tự gán giường,
// sinh viên phải đăng ký lại nếu vẫn muốn ở KTX. Chạy dày (5 phút, giống
// registration-periods:auto-close-admission) thay vì đúng 1 lần/ngày lúc 00:20 —
// lệnh idempotent (chỉ xử lý đúng những hồ sơ thật sự quá hạn), chạy thường xuyên
// hơn giúp huỷ kịp thời hơn, và tránh việc test/local nhảy giờ bỏ lỡ đúng mốc 00:20.
Schedule::command('bed-selection:expire')->everyFiveMinutes()->withoutOverlapping();

// Huỷ giữ chỗ của sinh viên đã chọn giường nhưng quá hạn đóng tiền lần đầu
// (initial_payment_due_days): giải phóng giường, sinh viên phải đăng ký lại.
// Chạy dày (5 phút) — chuyển trạng thái một chiều, idempotent, giống bed-selection:expire.
Schedule::command('initial-payment:expire')->everyFiveMinutes()->withoutOverlapping();

// Tự động bắt đầu bảo trì phòng đã đến ngày started_at. Chạy dày (5 phút) — idempotent,
// đã có kiểm tra còn sót người chưa xử lý (xem chi tiết AutoStartMaintenanceCommand).
Schedule::command('maintenance:auto-start')->everyFiveMinutes()->withoutOverlapping();

// Tự động hoàn tất bảo trì đã đến ngày dự kiến kết thúc. Chạy dày (5 phút) — một chiều, idempotent.
Schedule::command('maintenance:auto-complete')->everyFiveMinutes()->withoutOverlapping();

// Gửi thông báo hệ thống đã hẹn giờ.
Schedule::command('system-announcements:send-scheduled')->everyMinute()->withoutOverlapping();

// Đồng bộ lại AWS Rekognition Collection: dọn face trôi dạt/mồ côi, index lại đúng
// sinh viên có bản ghi lưu trú hợp lệ (chạy hàng tuần, giờ thấp điểm)
Schedule::command('rekognition:resync --force')->weeklyOn(0, '03:00')->withoutOverlapping();
