<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Registration;
use App\Models\Student;
use App\Models\Account;
use Carbon\Carbon;

class RegistrationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Dữ liệu lấy từ file SQL bạn cung cấp; dùng student_code để tìm student_id khi có
        $seedRows = [
            [
                'student_code' => 'DH12234456',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'father_name' => 'Nguyễn E',
                'father_birth_year' => '',
                'father_job' => '',
                'father_phone' => '0321478569',
                'mother_name' => '',
                'mother_birth_year' => '',
                'mother_job' => '',
                'mother_phone' => '',
                'parent_address' => '250 Nguyễn Tri Phương , Quận 10, TP. Hồ Chí Minh',
                'stay_from_date' => '2026-05-10',
                'stay_to_date' => '2026-05-10',
                'cccd_front_url' => 'registrations/cccd/3BkHKFjb4JpWSw4bDyd7wF7TMgN4WeM1umv6OV76.jpg',
                'cccd_back_url' => 'registrations/cccd/0J22JxMi68GaJiU9fMLgHGbqx0iCcG0lhVpwMLTu.jpg',
                'commitment_confirm' => 0,
                'status' => 'rejected',
                'note' => null,
                'reason' => 'không',
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
            ],
            [
                'student_code' => 'DH12234456',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'father_name' => 'Nguyễn D',
                'father_birth_year' => '',
                'father_job' => '',
                'father_phone' => '0321478569',
                'mother_name' => '',
                'mother_birth_year' => '',
                'mother_job' => '',
                'mother_phone' => '',
                'parent_address' => '180 Cao Lo, Quan 8, Ho Chi Minh',
                'stay_from_date' => '2026-05-10',
                'stay_to_date' => '2026-05-10',
                'cccd_front_url' => 'registrations/cccd/Ab1W0agGd928bjjO7semwEUyVKgDjGEklTiFAprF.png',
                'cccd_back_url' => 'registrations/cccd/AXTFqfw6YJ7UezsxOsFhBkwvMxuntfnQJrPi0bQS.jpg',
                'commitment_confirm' => 0,
                'status' => 'approved',
                'note' => null,
                'reason' => null,
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
            ],
            [
                'student_code' => 'DH52201190',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'father_name' => 'Nguyễn E',
                'father_birth_year' => '',
                'father_job' => '',
                'father_phone' => '0369852147',
                'mother_name' => '',
                'mother_birth_year' => '',
                'mother_job' => '',
                'mother_phone' => '',
                'parent_address' => 'xyz,abc,mnop',
                'stay_from_date' => '2026-05-14',
                'stay_to_date' => '2026-05-14',
                'cccd_front_url' => 'registrations/cccd/YbeaGuWsddRKaFh0KK4y1ZA0shHZQWAiTXKA2UY2.jpg',
                'cccd_back_url' => 'registrations/cccd/VI28ujnVRs5nEm0iEYP0geTzLd6LJ4zdMkK9MouR.jpg',
                'commitment_confirm' => 0,
                'status' => 'approved',
                'note' => null,
                'reason' => null,
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
            ],
            [
                'student_code' => 'DH52201699',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'father_name' => 'Nguyễn C',
                'father_birth_year' => '',
                'father_job' => '',
                'father_phone' => '0222255556',
                'mother_name' => '',
                'mother_birth_year' => '',
                'mother_job' => '',
                'mother_phone' => '',
                'parent_address' => '250 Nguyễn Tri Phương , Quận 5, TP. Hồ Chí Minh',
                'stay_from_date' => '2026-05-14',
                'stay_to_date' => '2026-05-14',
                'cccd_front_url' => 'registrations/cccd/vlU81uQkpuC6kFcxxIqNEpUXly6HIp8hmNZIRZcI.jpg',
                'cccd_back_url' => 'registrations/cccd/0zXHc1jns7qR4Qf5xy6bDkkQZp1OL6b874GFBUMR.jpg',
                'commitment_confirm' => 0,
                'status' => 'rejected',
                'note' => null,
                'reason' => 'fe',
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
            ],
            [
                'student_code' => 'DH52200662',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'father_name' => 'Nguyễn B',
                'father_birth_year' => '',
                'father_job' => 'không',
                'father_phone' => '0333666999',
                'mother_name' => 'Trần C',
                'mother_birth_year' => '',
                'mother_job' => 'không',
                'mother_phone' => '0111222333',
                'parent_address' => '511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh',
                'stay_from_date' => '2026-05-20',
                'stay_to_date' => '2027-05-20',
                'cccd_front_url' => 'registrations/cccd/SJHbXTBILiSo9FyUsa2Rw10rHDeGQGM2aNocoBe4.png',
                'cccd_back_url' => 'registrations/cccd/pCopZLmUhoE7CtQMAbFHpSJ0OxAOw6vuSmvtB96G.jpg',
                'commitment_confirm' => 1,
                'status' => 'approved',
                'note' => null,
                'reason' => null,
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
            ],
            [
                'student_code' => 'DH85236936',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'father_name' => 'Nguyễn M',
                'father_birth_year' => '',
                'father_job' => 'không',
                'father_phone' => '0333336541',
                'mother_name' => 'Trần B',
                'mother_birth_year' => '',
                'mother_job' => 'không',
                'mother_phone' => '0777778987',
                'parent_address' => '511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh',
                'stay_from_date' => '2026-05-20',
                'stay_to_date' => '2027-05-20',
                'cccd_front_url' => 'registrations/cccd/WmI7Fj2DXACQ265laDDrpK7jrJvaZ6UKNBfHcfFF.jpg',
                'cccd_back_url' => 'registrations/cccd/bZ7BwYhCd11f0PGWvrOe0CGkErVvEJWiggDKDSUB.jpg',
                'commitment_confirm' => 1,
                'status' => 'pending',
                'note' => null,
                'reason' => null,
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
            ],
            [
                'student_code' => 'DH52201202',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'father_name' => 'Nguyễn P',
                'father_birth_year' => '',
                'father_job' => 'không',
                'father_phone' => '0123654456',
                'mother_name' => 'Trần B',
                'mother_birth_year' => '',
                'mother_job' => 'không',
                'mother_phone' => '0123654741',
                'parent_address' => '511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh',
                'stay_from_date' => '2026-05-20',
                'stay_to_date' => '2027-05-20',
                'cccd_front_url' => 'registrations/cccd/3Ki7qWaiVGkv1mvzrVxHDPigZ3IDMOcwlD7ZZBo1.png',
                'cccd_back_url' => 'registrations/cccd/vpshsYHfDENemHrmSjMPXTmBUi6s8tes0cawSKY5.png',
                'commitment_confirm' => 1,
                'status' => 'rejected',
                'note' => null,
                'reason' => 'cc',
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
            ],
        ];

        foreach ($seedRows as $r) {
            // tìm student_id: ưu tiên Student, fallback Account
            $student = Student::where('student_code', $r['student_code'])->first();
            $studentId = null;
            if ($student) {
                $studentId = $student->id;
            } else {
                $acc = Account::where('student_code', $r['student_code'])->first();
                if ($acc && $acc->student_id) $studentId = $acc->student_id;
            }

            if (!$studentId) {
                // không tìm thấy student; bỏ qua để không gây lỗi khóa ngoại
                continue;
            }

            Registration::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'stay_from_date' => $r['stay_from_date'],
                    'stay_to_date' => $r['stay_to_date'],
                ],
                [
                    'semester' => $r['semester'],
                    'school_year' => $r['school_year'],
                    'father_name' => $r['father_name'],
                    'father_birth_year' => $r['father_birth_year'],
                    'father_job' => $r['father_job'],
                    'father_phone' => $r['father_phone'],
                    'mother_name' => $r['mother_name'],
                    'mother_birth_year' => $r['mother_birth_year'],
                    'mother_job' => $r['mother_job'],
                    'mother_phone' => $r['mother_phone'],
                    'parent_address' => $r['parent_address'],
                    'cccd_front_url' => $r['cccd_front_url'],
                    'cccd_back_url' => $r['cccd_back_url'],
                    'commitment_confirm' => $r['commitment_confirm'],
                    'status' => $r['status'],
                    'note' => $r['note'],
                    'reason' => $r['reason'],
                    'assigned_bed_id' => $r['assigned_bed_id'],
                    'assigned_room_id' => $r['assigned_room_id'],
                    'approved_at' => $r['approved_at'] ? Carbon::parse($r['approved_at']) : null,
                ]
            );
        }
    }
}
