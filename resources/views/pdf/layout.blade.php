<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Tài liệu KTX')</title>
    <style>
        @page {
            margin: 32px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1f3152;
            margin: 0;
        }

        .pdf-title-bar {
            padding: 14px 18px;
            margin-bottom: 14px;
            text-align: center;
        }

        .pdf-title-bar h1 {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1f3152;
        }

        .pdf-title-bar p {
            margin: 4px 0 0;
            font-size: 10px;
            color: #5a78a8;
        }

        .pdf-footer {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px solid #e2eaf6;
            font-size: 9px;
            color: #6f84ad;
            text-align: center;
        }

        .pdf-meta {
            font-size: 10px;
            color: #5a78a8;
            margin-bottom: 12px;
            text-align: right;
        }

        h2.section-title {
            font-size: 13px;
            color: #244cb8;
            border-bottom: 1px solid #dce7f8;
            padding-bottom: 4px;
            margin: 18px 0 8px;
        }

        table.pdf-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        table.pdf-table th,
        table.pdf-table td {
            border: 1px solid #dce7f8;
            padding: 5px 7px;
            font-size: 10px;
            text-align: left;
            vertical-align: top;
        }

        table.pdf-table th {
            background: #eef5ff;
            color: #1f3152;
            font-weight: bold;
        }

        table.pdf-table td.text-right,
        table.pdf-table th.text-right {
            text-align: right;
        }

        table.pdf-table td.text-center,
        table.pdf-table th.text-center {
            text-align: center;
        }

        table.pdf-table tfoot td {
            font-weight: bold;
            background: #f7faff;
        }

        .info-box {
            background: #f7faff;
            border: 1px solid #dce7f8;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 14px;
        }

        .info-box .row {
            margin-bottom: 3px;
        }

        .info-box .label {
            color: #5a78a8;
            display: inline-block;
            width: 130px;
        }

        .note {
            font-size: 9px;
            color: #92400e;
            background: #fff8e6;
            border-left: 3px solid #f59e0b;
            padding: 6px 10px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="pdf-title-bar">
        <h1>@yield('title', 'Tài liệu KTX')</h1>
        <p>Ký túc xá - Trường Đại học Công nghệ Sài Gòn (STU)</p>
    </div>

    <div class="pdf-meta">
        Ngày xuất: {{ \App\Support\VnFormat::datetime(now()) }}
    </div>

    @yield('content')

    <div class="pdf-footer">
        Tài liệu được tạo tự động từ Hệ thống Quản lý KTX — STU
    </div>
</body>
</html>
