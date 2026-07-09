<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông báo mở đợt gia hạn lưu trú</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6fb; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 32px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.10); }
        .header { background: linear-gradient(135deg, #1d3f9c 0%, #244cb8 60%, #31b7d4 100%); padding: 28px 32px; }
        .header h1 { color: #ffffff; font-size: 20px; margin: 0; }
        .header p { color: rgba(255,255,255,0.82); font-size: 13px; margin: 6px 0 0; }
        .body { padding: 28px 32px; color: #1f3152; font-size: 15px; line-height: 1.7; }
        .highlight { background: #eef5ff; border-left: 4px solid #244cb8; border-radius: 4px; padding: 12px 16px; margin: 18px 0; }
        .highlight strong { color: #244cb8; }
        .warning { background: #fff8e6; border-left: 4px solid #f59e0b; border-radius: 4px; padding: 12px 16px; margin: 18px 0; font-size: 14px; color: #92400e; }
        .footer { background: #f7faff; padding: 16px 32px; font-size: 12px; color: #6f84ad; border-top: 1px solid #e2eaf6; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Thông báo gia hạn lưu trú</h1>
            <p>Ký túc xá Trường Đại học</p>
        </div>
        <div class="body">
            <p>Kính gửi <strong>{{ $studentName }}</strong>,</p>

            <p>Đợt gia hạn lưu trú <strong>{{ $periodName }}</strong> hiện đã mở.</p>

            <div class="highlight">
                <strong>Hạn nộp yêu cầu gia hạn:</strong>
                {{ $periodEndDateLabel }}
            </div>

            <p>
                Nếu bạn muốn <strong>tiếp tục ở ký túc xá</strong> sau khi hết hạn lưu trú hiện tại,
                vui lòng đăng nhập hệ thống và gửi yêu cầu gia hạn trước thời hạn trên.
            </p>

            <div class="warning">
                Nếu bạn <strong>không gửi yêu cầu</strong> trong thời gian quy định,
                hệ thống sẽ xem như bạn <strong>không gia hạn</strong> và giường của bạn
                sẽ được giải phóng để phân cho sinh viên mới sau khi hết hạn lưu trú.
            </div>

            <p>Trân trọng,<br><strong>Ban quản lý Ký túc xá</strong></p>
        </div>
        <div class="footer">
            Email này được gửi tự động. Vui lòng không trả lời email này.
        </div>
    </div>
</body>
</html>
