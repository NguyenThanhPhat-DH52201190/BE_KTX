<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StudentSeeder extends Seeder
{
    /**
     * Tạo 100 sinh viên giả:
     *  - Giới tính trùng với tên đọc rõ ràng nam/nữ.
     *  - 50-60% học vụ là đang học (studying), phần còn lại trải đều các trạng thái khác.
     *  - Đa số dân tộc Kinh, có thêm các dân tộc khác và nhiều tỉnh/thành trên toàn quốc.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $provinces = [
            ['01', 'Thành phố Hà Nội'],
            ['31', 'Thành phố Hải Phòng'],
            ['46', 'Thành phố Huế'],
            ['48', 'Thành phố Đà Nẵng'],
            ['79', 'Thành phố Hồ Chí Minh'],
            ['92', 'Thành phố Cần Thơ'],
            ['04', 'Tỉnh Cao Bằng'],
            ['08', 'Tỉnh Tuyên Quang'],
            ['10', 'Tỉnh Lào Cai'],
            ['11', 'Tỉnh Điện Biên'],
            ['12', 'Tỉnh Lai Châu'],
            ['14', 'Tỉnh Sơn La'],
            ['19', 'Tỉnh Thái Nguyên'],
            ['20', 'Tỉnh Lạng Sơn'],
            ['22', 'Tỉnh Quảng Ninh'],
            ['25', 'Tỉnh Phú Thọ'],
            ['27', 'Tỉnh Bắc Ninh'],
            ['33', 'Tỉnh Hưng Yên'],
            ['37', 'Tỉnh Ninh Bình'],
            ['38', 'Tỉnh Thanh Hóa'],
            ['40', 'Tỉnh Nghệ An'],
            ['42', 'Tỉnh Hà Tĩnh'],
            ['45', 'Tỉnh Quảng Trị'],
            ['51', 'Tỉnh Quảng Ngãi'],
            ['56', 'Tỉnh Khánh Hòa'],
            ['64', 'Tỉnh Gia Lai'],
            ['66', 'Tỉnh Đắk Lắk'],
            ['68', 'Tỉnh Lâm Đồng'],
            ['72', 'Tỉnh Tây Ninh'],
            ['75', 'Tỉnh Đồng Nai'],
            ['86', 'Tỉnh Vĩnh Long'],
            ['87', 'Tỉnh Đồng Tháp'],
            ['89', 'Tỉnh An Giang'],
            ['96', 'Tỉnh Cà Mau'],
        ];

        $academicStatuses = [
            'studying',
            'temporary_leave',
            'dropped_out',
            'suspended',
            'waiting_graduation',
            'graduated',
            'overtime_training',
            'transferred',
        ];

        $faculties = [
            'Công nghệ thông tin',
            'Kinh tế - Quản trị',
            'Cơ khí',
            'Điện - Điện tử',
            'Xây dựng',
        ];
        $facultyCodes = ['TH', 'QT', 'CK', 'DD', 'XD'];

        $surnames = [
            'Nguyễn', 'Trần', 'Lê', 'Phạm', 'Võ', 'Đặng', 'Bùi', 'Huỳnh',
            'Phan', 'Ngô', 'Đỗ', 'Mai', 'Lý', 'Trịnh', 'Dương', 'Lâm',
        ];

        $maleNames = [
            'Minh Anh', 'Hoàng Nam', 'Gia Huy', 'Thành Đạt', 'Quốc Bảo',
            'Đức Anh', 'Minh Quân', 'Nhật Hào', 'Thanh Tùng', 'Hoàng Phúc',
            'Anh Tuấn', 'Gia Hưng', 'Quốc Khánh', 'Thành Công', 'Minh Đức',
            'Nhật Minh', 'Anh Khoa', 'Gia Bảo', 'Minh Triết', 'Bảo Long',
            'Trọng Nghĩa', 'Hữu Phước', 'Tiến Đạt', 'Khải Sơn', 'Hoài Nam',
            'Văn Khánh', 'Phong Vũ', 'Nhật Tuấn', 'Minh Duy', 'Anh Khang',
        ];

        $femaleNames = [
            'Thị Mai', 'Ngọc Anh', 'Thảo Vy', 'Quỳnh Như', 'Khánh Linh',
            'Mỹ Duyên', 'Thanh Trúc', 'Ngọc Hân', 'Bảo Ngọc', 'Minh Thư',
            'Hải Yến', 'Phương Anh', 'Ngọc Mai', 'Thu Hà', 'Kim Ngân',
            'Diệu Linh', 'Thùy Dung', 'Thuý Vy', 'Hồng Nhung', 'Trúc Mai',
            'Phương Thảo', 'Ánh Nguyệt', 'Nhật Hạ', 'Linh Chi', 'Bảo Trâm',
            'Mỹ Linh', 'Thuỳ An', 'Diễm My', 'Ngọc Thảo', 'Thanh Hà',
        ];

        $ethnicities = [
            'Kinh', 'Tày', 'Thái', 'Mường', 'Khmer', 'Hoa', 'Nùng', 'Chăm', 'Dao',
        ];

        $religions = ['Không', 'Phật giáo', 'Thiên Chúa giáo'];

        $rows = [];
        $courseYears = ['D20', 'D21', 'D22', 'D23', 'D24', 'D25'];
        $courseCounts = [17, 17, 17, 17, 16, 16];

        for ($i = 0; $i < 100; $i++) {
            $provinceIndex = $i % count($provinces);
            [$provinceCode, $provinceName] = $provinces[$provinceIndex];
            $facultyIndex = $i % count($faculties);
            $gender = $i % 2 === 0 ? 'male' : 'female';
            $givenName = $gender === 'male'
                ? $maleNames[$i % count($maleNames)]
                : $femaleNames[$i % count($femaleNames)];
            $surname = $surnames[$i % count($surnames)];
            $remaining = $i;
            $courseIndex = 0;
            foreach ($courseCounts as $index => $count) {
                if ($remaining < $count) {
                    $courseIndex = $index;
                    break;
                }
                $remaining -= $count;
            }
            $courseYear = $courseYears[$courseIndex];
            $birthYear = 2002 + $courseIndex;
            // Phần lớn studying (86 sinh viên), các trạng thái khác phân bổ 2 sinh viên/trạng thái
            if ($i < 86) {
                $academicStatus = 'studying';
            } else {
                $otherStatuses = ['temporary_leave', 'dropped_out', 'suspended', 'waiting_graduation', 'graduated', 'overtime_training', 'transferred'];
                $statusIndex = (($i - 86) / 2) % count($otherStatuses);
                $academicStatus = $otherStatuses[(int)$statusIndex];
            }
            $ethnicity = $i % 10 < 7
                ? 'Kinh'
                : $ethnicities[1 + (($i - 7) % (count($ethnicities) - 1))];
            $religion = $i % 10 < 8
                ? 'Không'
                : $religions[1 + (($i - 8) % (count($religions) - 1))];
            $day = str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT);
            $month = str_pad((string) ((($i % 12) + 1)), 2, '0', STR_PAD_LEFT);
            $currentYear = $academicStatus === 'studying'
                ? (($i % 4) + 1)
                : 4;

            $rows[] = [
                'student_code' => 'DH5250' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'full_name' => $surname . ' ' . $givenName,
                'date_of_birth' => "{$birthYear}-{$month}-{$day}",
                'gender' => $gender,
                'class_name' => $courseYear . '_' . $facultyCodes[$facultyIndex] . str_pad((string) (($i % 40) + 1), 2, '0', STR_PAD_LEFT),
                'faculty' => $faculties[$facultyIndex],
                'course_year' => $courseYear,
                'phone' => '09' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                'email' => 'dh5250' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT) . '@student.stu.edu.vn',
                'cccd' => str_pad((string) $provinceCode, 3, '0', STR_PAD_LEFT) . str_pad((string) (100000000 + $i), 9, '0', STR_PAD_LEFT),
                'cccd_issued_date' => '2022-01-01',
                'cccd_issued_place' => 'Cục Cảnh sát QLHC về TTXH',
                'nationality' => 'Việt Nam',
                'ethnicity' => $ethnicity,
                'religion' => $religion,
                'permanent_address' => 'Số ' . ($i + 1) . ' Phường Trung Hòa, ' . $provinceName,
                'province_code' => $provinceCode,
                'avatar' => null,
                'status' => 'active',
                'academic_status' => $academicStatus,
                'current_year' => $currentYear,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($rows as $row) {
            DB::table('students')->updateOrInsert(
                ['student_code' => $row['student_code']],
                $row
            );
        }
    }
}
