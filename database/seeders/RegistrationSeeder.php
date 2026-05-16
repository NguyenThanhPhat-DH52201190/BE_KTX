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
                'form_data' => '{"cccd": "021258123654", "mssv": "DH12234456", "class": "D22_TH03", "phone": "0123456789", "gender": "male", "address": "250 Nguyễn Tri Phương , Quận 10, TP. Hồ Chí Minh", "fullName": "Nguyễn Văn A", "religion": "Không", "birthDate": "2004-02-12", "ethnicity": "Kinh", "department": "Kinh tế - Quản trị", "father_job": "", "mother_job": "", "father_name": "Nguyễn E", "mother_name": "", "nationality": "Việt Nam", "father_phone": "0321478569", "mother_phone": "", "cccdIssueDate": null, "cccdIssuePlace": null, "familyContactAddress": "250 Nguyễn Tri Phương , Quận 10, TP. Hồ Chí Minh"}',
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
                'created_at' => '2026-05-10 05:06:32',
                'updated_at' => '2026-05-15 08:37:47',
            ],
            [
                'student_code' => 'DH12234456',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'form_data' => '{"cccd": "021258123654", "mssv": "DH12234456", "class": "D22_TH03", "phone": "0123456789", "gender": "male", "address": "180 Cao Lo, Quan 8, Ho Chi Minh", "fullName": "Nguyễn Văn A", "religion": "Không", "birthDate": "2004-02-12", "ethnicity": "Kinh", "department": "Kinh tế - Quản trị", "father_job": "", "mother_job": "", "father_name": "Nguyễn D", "mother_name": "", "nationality": "Việt Nam", "father_phone": "0321478569", "mother_phone": "", "cccdIssueDate": null, "cccdIssuePlace": null, "familyContactAddress": "180 Cao Lo, Quan 8, Ho Chi Minh"}',
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
                'created_at' => '2026-05-10 05:23:09',
                'updated_at' => '2026-05-15 08:37:47',
            ],
            [
                'student_code' => 'DH52201190',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'form_data' => '{"cccd": "036996336978", "mssv": "DH52201190", "class": "D22_TH03", "phone": "0123465788", "gender": "male", "address": "xyz,abc,mnop", "fullName": "Phát Nguyễn Thanh", "religion": "Không", "birthDate": "2004-02-12", "ethnicity": "Kinh", "department": "Xây Dựng", "father_job": "", "mother_job": "", "father_name": "Nguyễn E", "mother_name": "", "nationality": "Việt Nam", "father_phone": "0369852147", "mother_phone": "", "cccdIssueDate": null, "cccdIssuePlace": null, "familyContactAddress": "xyz,abc,mnop"}',
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
                'created_at' => '2026-05-14 01:12:56',
                'updated_at' => '2026-05-15 08:37:47',
            ],
            [
                'student_code' => 'DH52201699',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'form_data' => '{"cccd": "045698712336", "mssv": "DH52201699", "class": "D22_TH14", "phone": "0258852963", "gender": "male", "address": "abc,xyz,mnpq", "fullName": "Nguyễn Thị Cẩm Tú", "religion": "Không", "birthDate": "2004-08-12", "ethnicity": "Kinh", "department": "Công nghệ thực phẩm", "father_job": "", "mother_job": "", "father_name": "Nguyễn D", "mother_name": "", "nationality": "Hoa Kỳ", "father_phone": "0357896214", "mother_phone": "", "cccdIssueDate": null, "cccdIssuePlace": null, "familyContactAddress": "abc,xyz,mnpq"}',
                'father_name' => 'Nguyễn D',
                'father_birth_year' => '',
                'father_job' => '',
                'father_phone' => '0357896214',
                'mother_name' => '',
                'mother_birth_year' => '',
                'mother_job' => '',
                'mother_phone' => '',
                'parent_address' => 'abc,xyz,mnpq',
                'stay_from_date' => '2026-05-14',
                'stay_to_date' => '2026-05-14',
                'cccd_front_url' => 'registrations/cccd/shZNY7a1kC745950qwGtng1z0JG9gdi1f9lzivSF.jpg',
                'cccd_back_url' => 'registrations/cccd/TAWqEqMuSP9nUvWmJDFHwp1WzLpwyAavNKsTpWqK.jpg',
                'commitment_confirm' => 0,
                'status' => 'approved',
                'note' => null,
                'reason' => null,
                'assigned_bed_id' => null,
                'assigned_room_id' => null,
                'approved_at' => null,
                'created_at' => '2026-05-14 01:31:44',
                'updated_at' => '2026-05-15 08:37:47',
            ],
            [
                'student_code' => 'DH52200662',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'form_data' => '{"cccd": "022233366664", "mssv": "DH52200662", "class": "D22_TH03", "phone": "0123456789", "gender": "male", "address": "511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh", "fullName": "Nguyễn Minh Hiền", "religion": "Không", "birthDate": "2004-02-12", "ethnicity": "Kinh", "department": "Kinh tế - Quản trị", "father_job": "không", "mother_job": "không", "father_name": "Nguyễn B", "mother_name": "Trần C", "nationality": "Hoa Kỳ", "father_phone": "0333666999", "mother_phone": "0111222333", "cccdIssueDate": null, "cccdIssuePlace": null, "familyContactAddress": "511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh"}',
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
                'created_at' => '2026-05-14 01:37:47',
                'updated_at' => '2026-05-15 08:37:47',
            ],
            [
                'student_code' => 'DH85236936',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'form_data' => '{"cccd": "014774117891", "mssv": "DH85236936", "class": "D22_TH03", "phone": "0987898778", "gender": "male", "address": "511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh", "fullName": "Nguyễn Văn A", "religion": "Không", "birthDate": "2004-08-12", "ethnicity": "Kinh", "department": "Kinh tế - Quản trị", "father_job": "không", "mother_job": "không", "father_name": "Nguyễn M", "mother_name": "Trần B", "nationality": "Hoa Kỳ", "father_phone": "0333336541", "mother_phone": "0777778987", "cccdIssueDate": null, "cccdIssuePlace": null, "familyContactAddress": "511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh"}',
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
                'created_at' => '2026-05-15 03:36:54',
                'updated_at' => '2026-05-15 08:37:47',
            ],
            [
                'student_code' => 'DH52201202',
                'semester' => '2024-2025',
                'school_year' => '2024-2025',
                'form_data' => '{"cccd": "012332112332", "mssv": "DH52201202", "class": "D22_TH03", "phone": "0123321123", "gender": "female", "address": "511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh", "fullName": "bin", "religion": "Không", "birthDate": "2004-08-12", "ethnicity": "Kinh", "department": "Điện - Điện tử", "father_job": "không", "mother_job": "không", "father_name": "Nguyễn P", "mother_name": "Trần B", "nationality": "Việt Nam", "father_phone": "0123654456", "mother_phone": "0123654741", "cccdIssueDate": null, "cccdIssuePlace": null, "familyContactAddress": "511/2 Nguyễn Tri Phương, tp.Hồ Chí Minh"}',
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
                'created_at' => '2026-05-15 08:06:00',
                'updated_at' => '2026-05-15 08:37:47',
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

            // Ensure cccd files exist under storage/app/public
            $placeholderPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
            if (!empty($r['cccd_front_url'])) {
                $frontPath = storage_path('app/public/' . $r['cccd_front_url']);
                if (!file_exists($frontPath)) {
                    @mkdir(dirname($frontPath), 0755, true);
                    file_put_contents($frontPath, $placeholderPng);
                }
            }
            if (!empty($r['cccd_back_url'])) {
                $backPath = storage_path('app/public/' . $r['cccd_back_url']);
                if (!file_exists($backPath)) {
                    @mkdir(dirname($backPath), 0755, true);
                    file_put_contents($backPath, $placeholderPng);
                }
            }

            // Create record only if an identical record (by student + dates + created_at) doesn't exist
            $existsQuery = Registration::where('student_id', $studentId)
                ->where('stay_from_date', $r['stay_from_date'])
                ->where('stay_to_date', $r['stay_to_date']);
            if (!empty($r['created_at'])) {
                $existsQuery->where('created_at', $r['created_at']);
            }

            if ($existsQuery->exists()) {
                continue;
            }

            Registration::create([
                'student_id' => $studentId,
                'semester' => $r['semester'],
                'school_year' => $r['school_year'],
                'form_data' => $r['form_data'] ?? null,
                'father_name' => $r['father_name'],
                'father_birth_year' => $r['father_birth_year'],
                'father_job' => $r['father_job'],
                'father_phone' => $r['father_phone'],
                'mother_name' => $r['mother_name'],
                'mother_birth_year' => $r['mother_birth_year'],
                'mother_job' => $r['mother_job'],
                'mother_phone' => $r['mother_phone'],
                'parent_address' => $r['parent_address'],
                'stay_from_date' => $r['stay_from_date'],
                'stay_to_date' => $r['stay_to_date'],
                'cccd_front_url' => $r['cccd_front_url'],
                'cccd_back_url' => $r['cccd_back_url'],
                'commitment_confirm' => $r['commitment_confirm'],
                'status' => $r['status'],
                'note' => $r['note'],
                'reason' => $r['reason'],
                'assigned_bed_id' => $r['assigned_bed_id'],
                'assigned_room_id' => $r['assigned_room_id'],
                'approved_at' => $r['approved_at'] ? Carbon::parse($r['approved_at']) : null,
                'created_at' => isset($r['created_at']) ? Carbon::parse($r['created_at']) : Carbon::now(),
                'updated_at' => isset($r['updated_at']) ? Carbon::parse($r['updated_at']) : Carbon::now(),
            ]);
        }
    }
}
