<?php

namespace Database\Seeders;

use App\Models\Student;
use Illuminate\Database\Seeder;

/**
 * Seed QA thủ công — điền đủ 12 cột cha/mẹ + liên hệ khẩn cấp cho các sinh viên
 * demo/test (99 mã DH52500001-DH52500100 + 2 MSSV thật DH52201699,
 * DH52201190) để test tính năng prefill Bước 2.
 *
 * Tên/nghề nghiệp/địa chỉ xoay vòng qua vài bộ hồ sơ khác nhau cho tự nhiên,
 * nhưng SĐT cha/mẹ/khẩn cấp LUÔN bắt đầu "09000000xx" (8 số đầu cố định) để
 * sau này còn lọc/dọn được bằng where('phone', 'like', '090000000%').
 *
 * KHÔNG đưa vào DatabaseSeeder chính — chỉ chạy tay 1 lần khi cần:
 *   php artisan db:seed --class=QaDemoStudentFamilySeeder
 */
class QaDemoStudentFamilySeeder extends Seeder
{
    /**
     * Vài bộ hồ sơ gia đình xoay vòng — father_name/mother_name/emergency_contact_name
     * phải qua backend validate dạng "chỉ chữ cái + khoảng trắng" (StoreRegistrationRequest,
     * nameRegex) nên tuyệt đối không nhúng số/student_code vào 3 trường tên này.
     */
    private const PROFILES = [
        [
            'father_name' => 'Nguyễn Văn Bình', 'father_birth_year' => '1970', 'father_job' => 'Nông dân',
            'mother_name' => 'Trần Thị Lan', 'mother_birth_year' => '1972', 'mother_job' => 'Nội trợ',
            'parent_address' => 'Số 12, Đường Lê Lợi, Phường 4, TP. Cần Thơ',
            'emergency_contact_name' => 'Nguyễn Thị Hoa', 'emergency_contact_relationship' => 'sibling',
            'phone_suffix' => ['10', '11', '12'],
        ],
        [
            'father_name' => 'Trần Văn Đức', 'father_birth_year' => '1968', 'father_job' => 'Thợ điện',
            'mother_name' => 'Lê Thị Hồng', 'mother_birth_year' => '1971', 'mother_job' => 'Buôn bán nhỏ',
            'parent_address' => 'Số 45, Đường Nguyễn Trãi, Phường Hòa Khánh, TP. Đà Nẵng',
            'emergency_contact_name' => 'Trần Văn Minh', 'emergency_contact_relationship' => 'uncle',
            'phone_suffix' => ['20', '21', '22'],
        ],
        [
            'father_name' => 'Phạm Văn Hùng', 'father_birth_year' => '1973', 'father_job' => 'Công nhân xây dựng',
            'mother_name' => 'Đặng Thị Mai', 'mother_birth_year' => '1975', 'mother_job' => 'Giáo viên tiểu học',
            'parent_address' => 'Ấp Bình Đông, Xã Bình Phú, Huyện Châu Phú, An Giang',
            'emergency_contact_name' => 'Phạm Thị Ngọc', 'emergency_contact_relationship' => 'aunt',
            'phone_suffix' => ['30', '31', '32'],
        ],
        [
            'father_name' => 'Hoàng Văn Thanh', 'father_birth_year' => '1969', 'father_job' => 'Lái xe',
            'mother_name' => 'Vũ Thị Thu', 'mother_birth_year' => '1970', 'mother_job' => 'Kế toán',
            'parent_address' => 'Số 8, Ngõ 22, Đường Hoàng Văn Thụ, Quận Đống Đa, Hà Nội',
            'emergency_contact_name' => 'Hoàng Văn Long', 'emergency_contact_relationship' => 'grandparent',
            'phone_suffix' => ['40', '41', '42'],
        ],
        [
            'father_name' => 'Đỗ Văn Sơn', 'father_birth_year' => '1971', 'father_job' => 'Thợ mộc',
            'mother_name' => 'Bùi Thị Nga', 'mother_birth_year' => '1974', 'mother_job' => 'Y tá',
            'parent_address' => 'Thôn 3, Xã Bình Thạnh, Huyện Bình Sơn, Quảng Ngãi',
            'emergency_contact_name' => 'Đỗ Thị Quyên', 'emergency_contact_relationship' => 'parent',
            'phone_suffix' => ['50', '51', '52'],
        ],
        [
            'father_name' => 'Ngô Văn Tâm', 'father_birth_year' => '1967', 'father_job' => 'Ngư dân',
            'mother_name' => 'Phan Thị Yến', 'mother_birth_year' => '1969', 'mother_job' => 'Nội trợ',
            'parent_address' => 'Số 3, Đường Trần Phú, Phường Vĩnh Nguyên, TP. Nha Trang',
            'emergency_contact_name' => 'Ngô Văn Kiên', 'emergency_contact_relationship' => 'other',
            'phone_suffix' => ['60', '61', '62'],
        ],
    ];

    public function run(): void
    {
        $extraCodes = ['DH52201699', 'DH52201190'];

        $students = Student::where('student_code', 'like', 'DH525000%')
            ->orWhereIn('student_code', $extraCodes)
            ->orderBy('student_code')
            ->get();

        $this->command?->info("Danh sách {$students->count()} MSSV sẽ được ghi đè 12 field family/emergency:");
        foreach ($students as $s) {
            $this->command?->line("  {$s->student_code} — {$s->full_name}");
        }

        $profileCount = count(self::PROFILES);

        foreach ($students as $index => $student) {
            $profile = self::PROFILES[$index % $profileCount];
            [$fatherSuffix, $motherSuffix, $emergencySuffix] = $profile['phone_suffix'];

            $student->update([
                'father_name'       => $profile['father_name'],
                'father_birth_year' => $profile['father_birth_year'],
                'father_job'        => $profile['father_job'],
                'father_phone'      => '09000000' . $fatherSuffix,
                'mother_name'       => $profile['mother_name'],
                'mother_birth_year' => $profile['mother_birth_year'],
                'mother_job'        => $profile['mother_job'],
                'mother_phone'      => '09000000' . $motherSuffix,
                'parent_address'    => $profile['parent_address'],
                'emergency_contact_name'         => $profile['emergency_contact_name'],
                'emergency_contact_phone'        => '09000000' . $emergencySuffix,
                'emergency_contact_relationship' => $profile['emergency_contact_relationship'],
            ]);
        }

        $this->command?->info("Đã seed 12 cột family/emergency cho {$students->count()} sinh viên (xoay vòng " . $profileCount . " bộ hồ sơ).");
    }
}
