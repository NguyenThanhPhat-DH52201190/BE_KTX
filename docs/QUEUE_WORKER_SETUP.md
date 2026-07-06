# Queue worker (email gia hạn, và mọi email/job dùng queue sau này)

## Vì sao cần cái này

Email thông báo "đợt gia hạn đã mở" (và bất kỳ mail nào implement `ShouldQueue` sau này)
không gửi ngay trong lúc xử lý request — nó được ghi vào bảng `jobs` và chỉ thực sự được
gửi đi khi có 1 tiến trình **queue worker** chạy để xử lý hàng đợi đó.

**Nếu không có worker nào chạy, job cứ nằm im trong bảng `jobs` mãi mãi — email sẽ không
bao giờ được gửi, và sẽ không có lỗi nào hiện ra để biết.** Vì vậy bước cài đặt dưới đây là
bắt buộc, không phải tùy chọn.

Cấu hình hiện tại (`.env`): `QUEUE_CONNECTION=database` — job lưu trong MySQL, bảng `jobs`
(job lỗi hết retry rơi vào bảng `failed_jobs`).

## Cách chạy tay (test nhanh, không bền)

```
cd D:\KTX\BE_KTX
D:\wamp64\bin\php\php8.3.14\php.exe artisan queue:work --tries=3 --backoff=30
```

Lệnh này chạy trong 1 cửa sổ terminal, dừng lại là hết chạy — chỉ dùng để test nhanh.
Để chạy **liên tục kể cả khi restart máy**, làm theo phần NSSM bên dưới.

## Cài đặt chạy nền bằng NSSM (khuyến nghị cho WAMP/Windows)

WAMP không có supervisor như Linux, nên dùng **NSSM (Non-Sucking Service Manager)** để bọc
lệnh `queue:work` thành 1 Windows Service — tự khởi động cùng máy, tự khởi động lại nếu bị
crash.

### Bước 1 — Tải NSSM

1. Vào https://nssm.cc/download, tải bản mới nhất (ví dụ `nssm-2.24.zip`).
2. Giải nén, chọn đúng thư mục theo kiến trúc máy (`win64` cho hầu hết máy hiện nay).
3. Copy `nssm.exe` vào 1 thư mục cố định, ví dụ `C:\nssm\nssm.exe` (để dễ gọi lại sau này).

### Bước 2 — Đăng ký service

Mở **PowerShell hoặc CMD với quyền Administrator** (chuột phải → "Run as administrator"),
chạy:

```
C:\nssm\nssm.exe install KTXQueueWorker
```

Lệnh này mở ra 1 cửa sổ cấu hình GUI của NSSM. Điền:

- **Path**: `D:\wamp64\bin\php\php8.3.14\php.exe`
- **Startup directory**: `D:\KTX\BE_KTX`
- **Arguments**: `artisan queue:work --tries=3 --backoff=30 --sleep=3 --max-time=3600`

  (`--max-time=3600` cho worker tự thoát ra sau 1 tiếng — NSSM sẽ tự khởi động lại nó,
  giúp giải phóng bộ nhớ tích tụ theo thời gian, đây là khuyến nghị chuẩn của Laravel khi
  chạy worker dài hạn.)

Qua tab **"Details"**: đặt Display name = `KTX Queue Worker`.

Qua tab **"Exit actions"**: để mặc định "Restart application" — đây chính là phần "tự khởi
động lại nếu crash".

Bấm **Install service**.

Nếu muốn làm hoàn toàn bằng dòng lệnh (không qua GUI), dùng:

```
C:\nssm\nssm.exe install KTXQueueWorker "D:\wamp64\bin\php\php8.3.14\php.exe" "artisan queue:work --tries=3 --backoff=30 --sleep=3 --max-time=3600"
C:\nssm\nssm.exe set KTXQueueWorker AppDirectory "D:\KTX\BE_KTX"
C:\nssm\nssm.exe set KTXQueueWorker DisplayName "KTX Queue Worker"
C:\nssm\nssm.exe set KTXQueueWorker Start SERVICE_AUTO_START
```

### Bước 3 — Khởi động service

```
C:\nssm\nssm.exe start KTXQueueWorker
```

Hoặc mở **Services** (gõ `services.msc` ở Start Menu) → tìm "KTX Queue Worker" → Start.

Vì `Start SERVICE_AUTO_START` đã đặt ở bước 2, service này sẽ **tự chạy lại mỗi khi khởi
động máy/server**, không cần bật tay lại.

### Bước 4 — Kiểm tra service đang chạy

Cách 1 — qua Services GUI: mở `services.msc`, tìm "KTX Queue Worker", cột **Status** phải
là "Running".

Cách 2 — qua PowerShell:

```
Get-Service KTXQueueWorker
```

Cách 3 — cách chắc chắn nhất, kiểm tra job có THỰC SỰ được xử lý không (không chỉ service
"Running" trên giấy):

```
cd D:\KTX\BE_KTX
php artisan queue:status
```

Lệnh này in ra số job đang chờ và số job lỗi. Nếu số job chờ (`pending`) cứ tăng dần theo
thời gian mà không giảm → service KHÔNG thực sự xử lý job, dù trạng thái "Running".

## Xem/gỡ job tồn đọng khi cần

```
php artisan queue:status          # số job chờ + số job lỗi, cảnh báo nếu tồn đọng nhiều
php artisan queue:failed          # liệt kê chi tiết từng job đã lỗi (kèm lỗi cụ thể)
php artisan queue:retry all       # thử gửi lại toàn bộ job đã lỗi
php artisan queue:flush          # xóa hết job lỗi (chỉ dùng khi chắc chắn không cần nữa)
```

Chỉ số tồn đọng (`pending_jobs`, `failed_jobs`) cũng hiện ngay trên trang Dashboard admin —
sẽ hiện 1 dòng cảnh báo màu vàng nếu có job lỗi, hoặc job chờ vượt quá 20.

## Lệnh quản lý service (dừng/xóa khi cần)

```
C:\nssm\nssm.exe stop KTXQueueWorker
C:\nssm\nssm.exe restart KTXQueueWorker
C:\nssm\nssm.exe remove KTXQueueWorker confirm   # gỡ hẳn service
```

## QUAN TRỌNG — checklist mỗi lần deploy / khởi động lại server thật

- [ ] Đã chạy `php artisan migrate` (đảm bảo có bảng `jobs`, `failed_jobs`).
- [ ] Service `KTXQueueWorker` đang **Running** (`Get-Service KTXQueueWorker`).
- [ ] `php artisan queue:status` cho số job chờ hợp lý (không tăng dần không kiểm soát).
- [ ] Nếu deploy code mới có đổi logic trong Job/Mailable đã queue: **restart** service
      (`nssm restart KTXQueueWorker`) — worker cũ vẫn chạy code cũ trong bộ nhớ cho tới khi
      được restart, dù đã pull code mới về server.

Nếu bỏ qua các bước trên, mọi email liên quan tới gia hạn lưu trú (và bất kỳ tính năng nào
sau này dùng queue) sẽ **âm thầm không được gửi**, không có thông báo lỗi nào xuất hiện.
