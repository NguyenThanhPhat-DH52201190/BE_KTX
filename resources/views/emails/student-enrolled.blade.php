<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông báo xác nhận nhập học</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6fb; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 32px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.10); }
        .header { background: linear-gradient(135deg, #1d3f9c 0%, #244cb8 60%, #31b7d4 100%); padding: 28px 32px; }
        .header h1 { color: #ffffff; font-size: 20px; margin: 0; }
        .header p { color: rgba(255,255,255,0.82); font-size: 13px; margin: 6px 0 0; }
        .body { padding: 28px 32px; color: #1f3152; font-size: 15px; line-height: 1.7; }
        .card { background: #eef5ff; border-left: 4px solid #244cb8; border-radius: 4px; padding: 14px 18px; margin: 18px 0; }
        .card .label { font-size: 12px; color: #5a78a8; margin-bottom: 4px; }
        .card .value { font-size: 20px; font-weight: bold; color: #244cb8; letter-spacing: 1px; }
        .steps { background: #f7faff; border: 1px solid #dce7f8; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
        .steps ol { margin: 8px 0 0; padding-left: 20px; }
        .steps li { margin-bottom: 8px; font-size: 14px; color: #2b3f66; }
        .steps li strong { color: #244cb8; }
        .warning { background: #fff8e6; border-left: 4px solid #f59e0b; border-radius: 4px; padding: 12px 16px; margin: 18px 0; font-size: 13px; color: #92400e; }
        .footer { background: #f7faff; padding: 16px 32px; font-size: 12px; color: #6f84ad; border-top: 1px solid #e2eaf6; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Xác nhận nhập học thành công</h1>
            <p>Ký túc xá — Trường Đại học Công nghệ TP.HCM (STU)</p>
        </div>
        <div class="body">
            <p>Kính gửi <strong>{{ $studentFullName }}</strong>,</p>

            <p>Chúc mừng! Bạn đã được <strong>xác nhận nhập học</strong> vào hệ thống của trường. Thông tin sinh viên của bạn:</p>

            <div class="card">
                <div class="label">Mã số sinh viên (MSSV)</div>
                <div class="value">{{ $studentCode }}</div>
            </div>

            @if($studentClassName)
            <p>Lớp: <strong>{{ $studentClassName }}</strong>{{ $studentFaculty ? ' — ' . $studentFaculty : '' }}</p>
            @endif

            <div class="steps">
                <p style="font-weight: bold; margin: 0 0 4px; color: #1a2d52;">Để đăng ký lưu trú KTX, bạn thực hiện theo các bước sau:</p>
                <ol>
                    <li>Truy cập trang web hệ thống KTX và chọn <strong>Đăng ký tài khoản</strong>.</li>
                    <li>Nhập <strong>MSSV {{ $studentCode }}</strong> để xác thực và tạo tài khoản.</li>
                    <li>Đăng nhập bằng tài khoản vừa tạo.</li>
                    <li>Vào mục <strong>Đăng ký lưu trú</strong> và điền đầy đủ thông tin (bao gồm thông tin phụ huynh, địa chỉ...).</li>
                    <li>Nộp đơn và chờ kết quả xét duyệt.</li>
                </ol>
            </div>

            <div class="warning">
                <strong>Lưu ý:</strong> Hồ sơ đăng ký giữ chỗ KTX trước đây của bạn đã được ghi nhận. Tuy nhiên, bạn vẫn cần hoàn tất đăng ký lưu trú chính thức theo quy trình trên để được xếp phòng.
            </div>

            <p>Nếu bạn cần hỗ trợ, vui lòng liên hệ Ban quản lý Ký túc xá.</p>

            <p>Trân trọng,<br><strong>Ban quản lý Ký túc xá STU</strong></p>
        </div>
        <div class="footer">
            Email này được gửi tự động khi bạn được xác nhận nhập học. Vui lòng không trả lời email này.
        </div>
    </div>
</body>
</html>
