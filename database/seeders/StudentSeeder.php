<?php

namespace Database\Seeders;

use App\Helpers\FacultyHelper;
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
        $facultyCodes = ['TH', 'QT', 'CK', 'DDT', 'XD'];

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
        $courseCounts = [17, 17, 17, 17, 16, 16];
        // Đếm sĩ số theo mã lớp (năm nhập học + khoa + số lớp) để không vượt quá 100
        // SV/lớp khi random số lớp — key là chính class_name sinh ra.
        $classCounts = [];

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
            // course_year lưu khoảng năm học (hệ 4 năm), tách biệt hoàn toàn với class_name.
            $admissionYear = 2020 + $courseIndex;
            $courseYear = $admissionYear . '-' . ($admissionYear + 4);
            $admissionYearShort = substr((string) $admissionYear, -2);
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

            // Số khoa tra theo faculty (config/faculties.php) — mặc định 5 (CNTT) nếu không khớp
            // được bảng ánh xạ, tránh sinh MSSV rỗng/lỗi cho seeder demo.
            $departmentNumber = FacultyHelper::resolveDepartmentNumber($faculties[$facultyIndex]) ?? 5;
            $studentCode = 'DH' . $departmentNumber . $admissionYearShort . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT);

            // class_name = D[năm nhập học 2 số]_[mã khoa viết tắt][số lớp 01-14 ngẫu nhiên].
            // Lớp đại học không cố định sĩ số nên không cần logic "đầy mới sang lớp mới" —
            // chỉ random lại nếu rơi trúng mã lớp đã đủ 100 SV.
            do {
                $classNumber = str_pad((string) random_int(1, 14), 2, '0', STR_PAD_LEFT);
                $className = "D{$admissionYearShort}_{$facultyCodes[$facultyIndex]}{$classNumber}";
            } while (($classCounts[$className] ?? 0) >= 100);
            $classCounts[$className] = ($classCounts[$className] ?? 0) + 1;

            $rows[] = [
                'student_code' => $studentCode,
                'full_name' => $surname . ' ' . $givenName,
                'date_of_birth' => "{$birthYear}-{$month}-{$day}",
                'gender' => $gender,
                'class_name' => $className,
                'faculty' => $faculties[$facultyIndex],
                'course_year' => $courseYear,
                'phone' => '09' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                'email' => null,
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

        // Khớp theo cccd (suy từ province_code + thứ tự $i, không đổi qua các lần sửa
        // công thức student_code) thay vì student_code — để chạy lại seeder này sau khi
        // đổi logic sinh MSSV (vd. đổi số khoa) không bị đụng unique constraint cccd/email
        // với chính các dòng demo cũ do lần seed trước để lại.
        foreach ($rows as $row) {
            DB::table('students')->updateOrInsert(
                ['cccd' => $row['cccd']],
                $row
            );
        }
    }
}
