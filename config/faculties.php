<?php

// Ánh xạ khoa/ngành => số khoa dùng trong MSSV (định dạng DH[khoa 1 số][năm 2 số][5 số cuối]).
// Đây là dữ liệu học vụ thuộc quyền trường (ngoài phạm vi hệ thống KTX) nên tạm lưu ở đây
// dưới dạng file, không tạo bảng DB riêng — thay bằng API thật của trường khi có.
// Khớp theo mã ngắn (không phân biệt hoa/thường) hoặc tên đầy đủ của ngành/khoa.
return [
    1 => ['name' => 'Cơ khí', 'codes' => ['CK']],
    3 => ['name' => 'Điện - Điện tử', 'codes' => ['DDT', 'DD']],
    5 => ['name' => 'Công nghệ thông tin', 'codes' => ['TH', 'CNTT']],
    6 => ['name' => 'Công nghệ thực phẩm', 'codes' => ['CNTP']],
    7 => ['name' => 'Kinh tế - Quản trị', 'codes' => ['QT', 'QTKD']],
    8 => ['name' => 'Xây dựng', 'codes' => ['XD']],
    9 => ['name' => 'Thiết kế', 'codes' => ['TK']],
];
