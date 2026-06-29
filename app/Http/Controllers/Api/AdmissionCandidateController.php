<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ProvinceHelper;
use App\Http\Controllers\Controller;
use App\Models\AdmissionCandidate;
use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\Student;
use App\Models\StudentPriority;
use App\Models\StudentPriorityEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Mail\StudentEnrolledMail;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AdmissionCandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AdmissionCandidate::with('student')
            ->withCount('dormReservations')
            ->orderByDesc('created_at');

        if ($s = $request->input('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('admission_code', 'like', "%{$s}%")
                  ->orWhere('full_name', 'like', "%{$s}%")
                  ->orWhere('cccd', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admission_code'       => ['required', 'string', 'max:100', 'unique:admission_candidates,admission_code'],
            'expected_student_code' => ['nullable', 'string', 'max:20'],
            'full_name'            => ['required', 'string', 'max:191'],
            'date_of_birth'        => ['required', 'date'],
            'gender'               => ['nullable', Rule::in(['male', 'female'])],
            'cccd'                 => ['nullable', 'string', 'max:20', 'unique:admission_candidates,cccd'],
            'phone'                => ['nullable', 'string', 'max:20'],
            'email'                => ['nullable', 'email', 'max:191'],
            'permanent_address'    => ['nullable', 'string'],
            'major_code'           => ['nullable', 'string', 'max:30'],
            'major_name'           => ['nullable', 'string', 'max:191'],
            'course_year'          => ['nullable', 'string', 'max:20'],
            'school_year'          => ['nullable', 'string', 'max:20'],
            'status'               => ['nullable', Rule::in(['admitted', 'enrolled', 'cancelled'])],
        ]);

        $candidate = AdmissionCandidate::create($data);

        return response()->json($candidate, 201);
    }

    public function show(int $id): JsonResponse
    {
        $candidate = AdmissionCandidate::with(['student', 'dormReservations.period'])->findOrFail($id);
        return response()->json($candidate);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $candidate = AdmissionCandidate::findOrFail($id);

        $data = $request->validate([
            'admission_code'       => ['sometimes', 'string', 'max:100', Rule::unique('admission_candidates', 'admission_code')->ignore($id)],
            'expected_student_code' => ['nullable', 'string', 'max:20'],
            'full_name'            => ['sometimes', 'string', 'max:191'],
            'date_of_birth'        => ['sometimes', 'date'],
            'gender'               => ['nullable', Rule::in(['male', 'female'])],
            'cccd'                 => ['nullable', 'string', 'max:20', Rule::unique('admission_candidates', 'cccd')->ignore($id)],
            'phone'                => ['nullable', 'string', 'max:20'],
            'email'                => ['nullable', 'email', 'max:191'],
            'permanent_address'    => ['nullable', 'string'],
            'major_code'           => ['nullable', 'string', 'max:30'],
            'major_name'           => ['nullable', 'string', 'max:191'],
            'course_year'          => ['nullable', 'string', 'max:20'],
            'school_year'          => ['nullable', 'string', 'max:20'],
            'status'               => ['nullable', Rule::in(['admitted', 'cancelled'])],
        ]);

        $candidate->update($data);

        return response()->json($candidate);
    }

    public function destroy(int $id): JsonResponse
    {
        $candidate = AdmissionCandidate::findOrFail($id);

        if ($candidate->status === 'enrolled') {
            return response()->json(['message' => 'Không thể xóa thí sinh đã nhập học.'], 422);
        }

        $hasActiveReservation = DormReservation::where('admission_candidate_id', $id)
            ->whereIn('status', ['submitted', 'approved', 'waitlisted'])
            ->exists();

        if ($hasActiveReservation) {
            return response()->json(['message' => 'Không thể xóa thí sinh đang có hồ sơ giữ chỗ đang hoạt động.'], 422);
        }

        $candidate->delete();

        return response()->json(['message' => 'Đã xóa hồ sơ thí sinh.']);
    }

    public function enroll(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'student_code'       => ['required', 'string', 'max:20'],
            'class_name'         => ['required', 'string', 'max:100'],
            'email'              => ['nullable', 'email', 'max:191'],
            'phone'              => ['nullable', 'string', 'max:20'],
            'faculty'            => ['nullable', 'string', 'max:191'],
            'course_year'        => ['nullable', 'string', 'max:20'],
            'current_year'       => ['nullable', 'integer', 'min:1', 'max:10'],
            'cccd_issued_date'   => ['nullable', 'date'],
            'cccd_issued_place'  => ['nullable', 'string', 'max:191'],
            'nationality'        => ['nullable', 'string', 'max:100'],
            'ethnicity'          => ['nullable', 'string', 'max:100'],
            'religion'           => ['nullable', 'string', 'max:100'],
            'permanent_address'  => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($id, $data) {
            $candidate = AdmissionCandidate::findOrFail($id);

            if ($candidate->status === 'cancelled') {
                return response()->json(['message' => 'Thí sinh đã bị huỷ, không thể nhập học.'], 422);
            }

            if ($candidate->status === 'enrolled' || $candidate->student_id !== null) {
                return response()->json(['message' => 'Thí sinh này đã được chuyển thành sinh viên rồi.'], 422);
            }

            // Kiểm tra MSSV trùng
            if (Student::where('student_code', $data['student_code'])->exists()) {
                return response()->json([
                    'message' => 'MSSV đã tồn tại trong hệ thống.',
                    'errors'  => ['student_code' => ['MSSV này đã được sử dụng.']],
                ], 422);
            }

            // Kiểm tra CCCD trùng trong bảng students
            if ($candidate->cccd) {
                $existingByCccd = Student::where('cccd', $candidate->cccd)->first();
                if ($existingByCccd) {
                    return response()->json([
                        'message' => "CCCD {$candidate->cccd} đã tồn tại trong hệ thống sinh viên (MSSV: {$existingByCccd->student_code}).",
                        'errors'  => ['cccd' => ['CCCD này đã thuộc về một sinh viên khác.']],
                    ], 422);
                }
            }

            // Kiểm tra email trùng (nếu có)
            $effectiveEmail = $data['email'] ?? $candidate->email;
            if ($effectiveEmail) {
                $existingByEmail = Student::where('email', $effectiveEmail)->first();
                if ($existingByEmail) {
                    return response()->json([
                        'message' => "Email {$effectiveEmail} đã tồn tại trong hệ thống sinh viên (MSSV: {$existingByEmail->student_code}).",
                        'errors'  => ['email' => ['Email này đã thuộc về một sinh viên khác.']],
                    ], 422);
                }
            }

            // Tạo bản ghi sinh viên
            $student = Student::create([
                'student_code'      => $data['student_code'],
                'full_name'         => $candidate->full_name,
                'date_of_birth'     => $candidate->date_of_birth,
                'gender'            => $candidate->gender,
                'class_name'        => $data['class_name'],
                'faculty'           => $data['faculty'] ?? null,
                'course_year'       => $data['course_year'] ?? $candidate->course_year,
                'phone'             => $data['phone'] ?? $candidate->phone,
                'email'             => $effectiveEmail,
                'cccd'              => $candidate->cccd,
                'cccd_issued_date'  => $data['cccd_issued_date'] ?? null,
                'cccd_issued_place' => $data['cccd_issued_place'] ?? null,
                'nationality'       => $data['nationality'] ?? null,
                'ethnicity'         => $data['ethnicity'] ?? null,
                'religion'          => $data['religion'] ?? null,
                'permanent_address' => $data['permanent_address'] ?? $candidate->permanent_address,
                'province_code'     => $data['province_code'] ?? ProvinceHelper::resolveCode($data['permanent_address'] ?? $candidate->permanent_address),
                'status'            => 'active',
                'academic_status'   => 'studying',
                'current_year'      => $data['current_year'] ?? 1,
            ]);

            // Cập nhật candidate
            $candidate->update([
                'status'      => 'enrolled',
                'student_id'  => $student->id,
                'enrolled_at' => now(),
            ]);

            // Cập nhật student_code cho các dorm_reservation liên quan
            DormReservation::where('admission_candidate_id', $candidate->id)
                ->update(['student_code' => $data['student_code']]);

            // Auto-tạo registration nếu reservation có đủ thông tin gia đình
            $fullReservation = DormReservation::where('admission_candidate_id', $candidate->id)
                ->whereIn('status', ['submitted', 'approved', 'waitlisted'])
                ->whereNotNull('father_name')
                ->latest()
                ->first();

            if ($fullReservation) {
                $period = RegistrationPeriod::find($fullReservation->registration_period_id);
                $reg = Registration::create([
                    'student_id'             => $student->id,
                    'registration_period_id' => $fullReservation->registration_period_id,
                    'registration_type'      => 'new',
                    'semester'               => $period?->semester,
                    'school_year'            => $period?->school_year,
                    'stay_from_date'         => $period?->stay_start_date?->format('Y-m-d'),
                    'stay_to_date'           => $period?->stay_end_date?->format('Y-m-d'),
                    'father_name'            => $fullReservation->father_name,
                    'father_birth_year'      => $fullReservation->father_birth_year,
                    'father_job'             => $fullReservation->father_job,
                    'father_phone'           => $fullReservation->father_phone,
                    'mother_name'            => $fullReservation->mother_name,
                    'mother_birth_year'      => $fullReservation->mother_birth_year,
                    'mother_job'             => $fullReservation->mother_job,
                    'mother_phone'           => $fullReservation->mother_phone,
                    'parent_address'         => $fullReservation->parent_address,
                    'commitment_confirm'     => $fullReservation->commitment_confirm ?? true,
                    'status'                 => 'submitted',
                    'avatar_url'             => $fullReservation->avatar_url,
                    'cccd_front_url'         => $fullReservation->cccd_front_url,
                    'cccd_back_url'          => $fullReservation->cccd_back_url,
                    'top_priority_tier'      => $fullReservation->top_priority_tier,
                    'total_priority_score'   => $fullReservation->total_priority_score,
                ]);
                $this->copyPrioritiesToRegistration($fullReservation, $student->id, $reg->id);
                $fullReservation->update([
                    'status'                    => 'converted',
                    'converted_registration_id' => $reg->id,
                ]);
            }

            // Gửi email thông báo cho sinh viên
            if ($student->email) {
                try {
                    Mail::to($student->email)->send(new StudentEnrolledMail($student));
                } catch (\Throwable) {
                    // Không block nếu gửi mail thất bại
                }
            }

            return response()->json([
                'message'   => 'Đã chuyển thành sinh viên thành công.' . ($student->email ? ' Email thông báo đã được gửi.' : ' Sinh viên chưa có email — cần tự đăng ký tài khoản bằng MSSV.'),
                'candidate' => $candidate->fresh(),
                'student'   => $student,
            ]);
        });
    }

    public function importTemplate(): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Danh sach sinh vien');

        $headers = [
            'A1' => 'student_code',
            'B1' => 'full_name',
            'C1' => 'date_of_birth',
            'D1' => 'gender',
            'E1' => 'class_name',
            'F1' => 'faculty',
            'G1' => 'course_year',
            'H1' => 'current_year',
            'I1' => 'email',
            'J1' => 'phone',
            'K1' => 'cccd',
            'L1' => 'cccd_issued_date',
            'M1' => 'cccd_issued_place',
            'N1' => 'nationality',
            'O1' => 'ethnicity',
            'P1' => 'religion',
            'Q1' => 'permanent_address',
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        // Style header row
        $sheet->getStyle('A1:Q1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '244CB8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Example row
        $sheet->setCellValue('A2', 'DH52507001');
        $sheet->setCellValue('B2', 'Nguyễn Văn An');
        $sheet->setCellValue('C2', '2006-05-15');
        $sheet->setCellValue('D2', 'male');
        $sheet->setCellValue('E2', 'D26CNTT01');
        $sheet->setCellValue('F2', 'Công nghệ thông tin');
        $sheet->setCellValue('G2', '2026-2030');
        $sheet->setCellValue('H2', 1);
        $sheet->setCellValue('I2', 'nguyenvanan26@student.edu.vn');
        $sheet->setCellValue('J2', '0901234567');
        $sheet->setCellValue('K2', '087654321001');
        $sheet->setCellValue('L2', '2021-01-15');
        $sheet->setCellValue('M2', 'Cục CS QLHC về TTXH - Bộ Công an');
        $sheet->setCellValue('N2', 'Việt Nam');
        $sheet->setCellValue('O2', 'Kinh');
        $sheet->setCellValue('P2', 'Không');
        $sheet->setCellValue('Q2', '123 Đường ABC, Phường XYZ, Quận 1, TP.HCM');

        // Auto-width
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="mau_import_sinh_vien.xlsx"',
        ]);
    }

    public function bulkEnroll(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $spreadsheet = IOFactory::load($request->file('file')->getPathname());
        $sheet       = $spreadsheet->getActiveSheet();
        $rawRows     = $sheet->toArray(null, true, true, true);

        if (count($rawRows) < 2) {
            return response()->json(['message' => 'File không có dữ liệu (chỉ có hàng tiêu đề hoặc rỗng).'], 422);
        }

        if (count($rawRows) > 1001) {
            return response()->json(['message' => 'Tối đa 1000 dòng mỗi lần import.'], 422);
        }

        // Map header column letters to field names
        $headerRow = $rawRows[1];
        $colMap    = [];
        foreach ($headerRow as $col => $val) {
            if ($val !== null && $val !== '') {
                $colMap[$col] = strtolower(trim((string) $val));
            }
        }

        $required = ['student_code', 'full_name', 'date_of_birth', 'class_name'];
        $missing  = array_diff($required, array_values($colMap));
        if ($missing) {
            return response()->json([
                'message' => 'File thiếu cột bắt buộc: ' . implode(', ', $missing) . '. Vui lòng dùng file template đúng định dạng.',
            ], 422);
        }

        $summary = ['total' => 0, 'success' => 0, 'skipped' => 0, 'error' => 0];
        $rows    = [];

        foreach ($rawRows as $rowIndex => $rawRow) {
            if ($rowIndex === 1) {
                continue;
            }

            $data = [];
            foreach ($colMap as $col => $field) {
                $data[$field] = isset($rawRow[$col]) && $rawRow[$col] !== '' ? trim((string) $rawRow[$col]) : null;
            }

            if (empty(array_filter($data))) {
                continue;
            }

            $summary['total']++;
            $rowResult = ['row' => $rowIndex, 'student_code' => $data['student_code'] ?? null];

            foreach ($required as $req) {
                if (empty($data[$req])) {
                    $summary['error']++;
                    $rows[] = array_merge($rowResult, ['status' => 'error', 'message' => "Thiếu {$req}."]);
                    continue 2;
                }
            }

            $result = DB::transaction(function () use ($data) {
                // Skip if MSSV already exists
                if (Student::where('student_code', $data['student_code'])->exists()) {
                    return ['status' => 'skipped', 'message' => "MSSV '{$data['student_code']}' đã tồn tại trong hệ thống."];
                }

                // Check CCCD collision
                if (!empty($data['cccd']) && Student::where('cccd', $data['cccd'])->exists()) {
                    return ['status' => 'error', 'message' => "CCCD '{$data['cccd']}' đã thuộc về sinh viên khác."];
                }

                // Check email collision
                if (!empty($data['email']) && Student::where('email', $data['email'])->exists()) {
                    return ['status' => 'error', 'message' => "Email '{$data['email']}' đã thuộc về sinh viên khác."];
                }

                $currentYear = isset($data['current_year']) && is_numeric($data['current_year'])
                    ? (int) $data['current_year']
                    : 1;

                // All data comes from Excel (from phòng đào tạo)
                $student = Student::create([
                    'student_code'      => $data['student_code'],
                    'full_name'         => $data['full_name'],
                    'date_of_birth'     => $data['date_of_birth'],
                    'gender'            => in_array($data['gender'] ?? '', ['male', 'female']) ? $data['gender'] : null,
                    'class_name'        => $data['class_name'],
                    'faculty'           => $data['faculty'] ?? null,
                    'course_year'       => $data['course_year'] ?? null,
                    'phone'             => $data['phone'] ?? null,
                    'email'             => $data['email'] ?? null,
                    'cccd'              => $data['cccd'] ?? null,
                    'cccd_issued_date'  => !empty($data['cccd_issued_date']) ? $data['cccd_issued_date'] : null,
                    'cccd_issued_place' => $data['cccd_issued_place'] ?? null,
                    'nationality'       => $data['nationality'] ?? null,
                    'ethnicity'         => $data['ethnicity'] ?? null,
                    'religion'          => $data['religion'] ?? null,
                    'permanent_address' => $data['permanent_address'] ?? null,
                    'province_code'     => $data['province_code'] ?? ProvinceHelper::resolveCode($data['permanent_address'] ?? null),
                    'status'            => 'active',
                    'academic_status'   => 'studying',
                    'current_year'      => $currentYear,
                ]);

                // Auto-link to admission_candidate by CCCD
                $linkedNote = '';
                if (!empty($data['cccd'])) {
                    $candidate = AdmissionCandidate::where('cccd', $data['cccd'])
                        ->whereNull('student_id')
                        ->first();

                    if ($candidate) {
                        $candidate->update([
                            'status'      => 'enrolled',
                            'student_id'  => $student->id,
                            'enrolled_at' => now(),
                        ]);

                        DormReservation::where('admission_candidate_id', $candidate->id)
                            ->update(['student_code' => $data['student_code']]);

                        $linkedNote = " Đã liên kết hồ sơ giữ chỗ ({$candidate->admission_code}).";

                        // Auto-tạo registration nếu reservation có đủ thông tin gia đình
                        $fullReservation = DormReservation::where('admission_candidate_id', $candidate->id)
                            ->whereIn('status', ['submitted', 'approved', 'waitlisted'])
                            ->whereNotNull('father_name')
                            ->latest()
                            ->first();

                        if ($fullReservation) {
                            $period = RegistrationPeriod::find($fullReservation->registration_period_id);
                            $reg = Registration::create([
                                'student_id'             => $student->id,
                                'registration_period_id' => $fullReservation->registration_period_id,
                                'registration_type'      => 'new',
                                'semester'               => $period?->semester,
                                'school_year'            => $period?->school_year,
                                'stay_from_date'         => $period?->stay_start_date?->format('Y-m-d'),
                                'stay_to_date'           => $period?->stay_end_date?->format('Y-m-d'),
                                'father_name'            => $fullReservation->father_name,
                                'father_birth_year'      => $fullReservation->father_birth_year,
                                'father_job'             => $fullReservation->father_job,
                                'father_phone'           => $fullReservation->father_phone,
                                'mother_name'            => $fullReservation->mother_name,
                                'mother_birth_year'      => $fullReservation->mother_birth_year,
                                'mother_job'             => $fullReservation->mother_job,
                                'mother_phone'           => $fullReservation->mother_phone,
                                'parent_address'         => $fullReservation->parent_address,
                                'commitment_confirm'     => $fullReservation->commitment_confirm ?? true,
                                'status'                 => 'submitted',
                                'avatar_url'             => $fullReservation->avatar_url,
                                'cccd_front_url'         => $fullReservation->cccd_front_url,
                                'cccd_back_url'          => $fullReservation->cccd_back_url,
                                'top_priority_tier'      => $fullReservation->top_priority_tier,
                                'total_priority_score'   => $fullReservation->total_priority_score,
                            ]);
                            $this->copyPrioritiesToRegistration($fullReservation, $student->id, $reg->id);
                            $fullReservation->update([
                                'status'                    => 'converted',
                                'converted_registration_id' => $reg->id,
                            ]);
                            $linkedNote .= ' Đã tự động tạo đơn đăng ký lưu trú.';
                        }
                    }
                }

                // Gửi email thông báo
                if ($student->email) {
                    try {
                        Mail::to($student->email)->send(new StudentEnrolledMail($student));
                    } catch (\Throwable) {
                        // Không block nếu gửi mail thất bại
                    }
                }

                return [
                    'status'       => 'success',
                    'student_code' => $data['student_code'],
                    'message'      => "Đã tạo sinh viên.{$linkedNote}" . ($student->email ? ' Email đã gửi.' : ''),
                ];
            });

            $summary[$result['status']]++;
            $rows[] = array_merge($rowResult, $result);
        }

        return response()->json(compact('summary', 'rows'));
    }

    private function copyPrioritiesToRegistration(DormReservation $reservation, int $studentId, int $registrationId): void
    {
        $reservation->load('reservationPriorities.evidences');
        foreach ($reservation->reservationPriorities as $rp) {
            $sp = StudentPriority::create([
                'student_id'           => $studentId,
                'registration_id'      => $registrationId,
                'priority_criteria_id' => $rp->priority_criteria_id,
                'status'               => $rp->status,
            ]);
            foreach ($rp->evidences as $ev) {
                StudentPriorityEvidence::create([
                    'student_priority_id' => $sp->id,
                    'file_url'            => $ev->file_url,
                ]);
            }
        }
    }
}
