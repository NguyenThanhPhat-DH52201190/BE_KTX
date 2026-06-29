<?php

namespace Database\Seeders;

use App\Models\AdmissionCandidate;
use Illuminate\Database\Seeder;

class AdmissionCandidateSeeder extends Seeder
{
    public function run(): void
    {
        $candidates = [
            [
                'admission_code'    => '01_TT_XTS_GBTT_00319',
                'expected_student_code' => null,
                'full_name'         => 'Nguyễn Văn An',
                'date_of_birth'     => '2006-03-15',
                'gender'            => 'male',
                'cccd'              => '052306001001',
                'phone'             => '0901234561',
                'email'             => 'nguyenvanan26@student.edu.vn',
                'permanent_address' => 'Thôn 3, Xã Bình Thạnh, Huyện Bình Sơn, Quảng Ngãi',
                'major_code'        => 'CNTT',
                'major_name'        => 'Công nghệ thông tin',
                'course_year'       => '2026-2030',
                'school_year'       => '2026-2027',
                'status'            => 'admitted',
            ],
            [
                'admission_code'    => '01_TT_XTS_GBTT_00427',
                'expected_student_code' => null,
                'full_name'         => 'Trần Thị Bích',
                'date_of_birth'     => '2006-07-22',
                'gender'            => 'female',
                'cccd'              => '079306002002',
                'phone'             => '0912345672',
                'email'             => 'tranthibibich26@student.edu.vn',
                'permanent_address' => 'Số 12, Đường Lê Lợi, Phường 2, TP. Vũng Tàu, Bà Rịa - Vũng Tàu',
                'major_code'        => 'KE',
                'major_name'        => 'Kế toán',
                'course_year'       => '2026-2030',
                'school_year'       => '2026-2027',
                'status'            => 'admitted',
            ],
            [
                'admission_code'    => '01_TT_XTS_GBTT_00583',
                'expected_student_code' => null,
                'full_name'         => 'Lê Minh Châu',
                'date_of_birth'     => '2006-11-08',
                'gender'            => 'male',
                'cccd'              => '048306003003',
                'phone'             => '0923456783',
                'email'             => 'leminhchau26@student.edu.vn',
                'permanent_address' => 'Ấp Bình Đông, Xã Bình Phú, Huyện Châu Phú, An Giang',
                'major_code'        => 'QTKD',
                'major_name'        => 'Quản trị kinh doanh',
                'course_year'       => '2026-2030',
                'school_year'       => '2026-2027',
                'status'            => 'admitted',
            ],
            [
                'admission_code'    => '01_TT_XTS_GBTT_00701',
                'expected_student_code' => null,
                'full_name'         => 'Phạm Thị Diễm',
                'date_of_birth'     => '2006-05-30',
                'gender'            => 'female',
                'cccd'              => '092306004004',
                'phone'             => '0934567894',
                'email'             => 'phamthidiem26@student.edu.vn',
                'permanent_address' => 'Xóm 7, Xã Tân Phú Đông, Huyện Sa Đéc, Đồng Tháp',
                'major_code'        => 'DL',
                'major_name'        => 'Du lịch',
                'course_year'       => '2026-2030',
                'school_year'       => '2026-2027',
                'status'            => 'admitted',
            ],
            [
                'admission_code'    => '01_TT_XTS_GBTT_00812',
                'expected_student_code' => 'DH52207012',
                'full_name'         => 'Hoàng Văn Em',
                'date_of_birth'     => '2006-01-17',
                'gender'            => 'male',
                'cccd'              => '001306005005',
                'phone'             => '0945678905',
                'email'             => 'hoangvanem26@student.edu.vn',
                'permanent_address' => '45 Ngõ 12, Đường Hoàng Văn Thụ, Phường Láng Hạ, Quận Đống Đa, Hà Nội',
                'major_code'        => 'XD',
                'major_name'        => 'Xây dựng dân dụng và công nghiệp',
                'course_year'       => '2026-2030',
                'school_year'       => '2026-2027',
                'status'            => 'admitted',
            ],
        ];

        foreach ($candidates as $data) {
            AdmissionCandidate::updateOrCreate(
                ['admission_code' => $data['admission_code']],
                $data
            );
        }
    }
}
