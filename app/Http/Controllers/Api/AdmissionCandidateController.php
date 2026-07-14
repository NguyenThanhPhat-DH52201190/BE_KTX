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
use App\Services\StudentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Mail\StudentEnrolledMail;
use Illuminate\Support\Facades\Log;
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

    // enroll() (nhập học từng người, tự sinh MSSV) đã bị BỎ HẲN — sai thẩm quyền: MSSV
    // do phòng đào tạo trường cấp, KTX không được tự quyết định ai nhập học/MSSV bao
    // nhiêu. Con đường DUY NHẤT chuyển admission_candidates → students là bulkEnroll()
    // (Import Excel), đọc student_code thật trực tiếp từ file, không tự sinh.

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
            'I1' => 'school_email',
            'J1' => 'phone',
            'K1' => 'cccd',
            'L1' => 'cccd_issued_date',
            'M1' => 'cccd_issued_place',
            'N1' => 'nationality',
            'O1' => 'ethnicity',
            'P1' => 'religion',
            'Q1' => 'permanent_address',
            // Cha/mẹ + liên hệ khẩn cấp — nguồn duy nhất từ đây (trường thu lúc làm thủ
            // tục nhập học trực tiếp), không còn thu lúc giữ chỗ online nữa.
            'R1' => 'father_name',
            'S1' => 'father_birth_year',
            'T1' => 'father_job',
            'U1' => 'father_phone',
            'V1' => 'mother_name',
            'W1' => 'mother_birth_year',
            'X1' => 'mother_job',
            'Y1' => 'mother_phone',
            'Z1' => 'parent_address',
            'AA1' => 'emergency_contact_name',
            'AB1' => 'emergency_contact_phone',
            'AC1' => 'emergency_contact_relationship',
            'AD1' => 'admission_code',
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        // Style header row
        $sheet->getStyle('A1:AD1')->applyFromArray([
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
        $sheet->setCellValue('I2', 'nguyenvanan26@stu.edu.vn');
        $sheet->setCellValue('J2', '0901234567');
        $sheet->setCellValue('K2', '087654321001');
        $sheet->setCellValue('L2', '2021-01-15');
        $sheet->setCellValue('M2', 'Cục CS QLHC về TTXH - Bộ Công an');
        $sheet->setCellValue('N2', 'Việt Nam');
        $sheet->setCellValue('O2', 'Kinh');
        $sheet->setCellValue('P2', 'Không');
        $sheet->setCellValue('Q2', '123 Đường ABC, Phường XYZ, Quận 1, TP.HCM');
        $sheet->setCellValue('R2', 'Nguyễn Văn Bình');
        $sheet->setCellValue('S2', '1975');
        $sheet->setCellValue('T2', 'Nông dân');
        $sheet->setCellValue('U2', '0912345678');
        $sheet->setCellValue('V2', 'Trần Thị Lan');
        $sheet->setCellValue('W2', '1978');
        $sheet->setCellValue('X2', 'Nội trợ');
        $sheet->setCellValue('Y2', '0912345679');
        $sheet->setCellValue('Z2', '123 Đường ABC, Phường XYZ, Quận 1, TP.HCM');
        $sheet->setCellValue('AA2', 'Nguyễn Thị Hoa');
        $sheet->setCellValue('AB2', '0912345680');
        $sheet->setCellValue('AC2', 'sibling');
        $sheet->setCellValue('AD2', '01_TT_XTS_GBTT_00319');

        // Auto-width
        foreach (array_keys($headers) as $cell) {
            $col = (string) preg_replace('/\d+$/', '', $cell);
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

        $required = ['student_code', 'full_name', 'date_of_birth', 'class_name', 'school_email'];
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

                $schoolEmail = $data['school_email'];
                if (!filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
                    return ['status' => 'error', 'message' => "Email trường '{$schoolEmail}' không hợp lệ."];
                }

                // Check school email collision.
                if (Student::where('email', $schoolEmail)->exists()) {
                    return ['status' => 'error', 'message' => "Email trường '{$schoolEmail}' đã thuộc về sinh viên khác."];
                }

                $candidate = null;
                if (!empty($data['admission_code'])) {
                    $candidate = AdmissionCandidate::where('admission_code', $data['admission_code'])->first();
                    if (!$candidate) {
                        return ['status' => 'error', 'message' => "Không tìm thấy hồ sơ trúng tuyển '{$data['admission_code']}'."];
                    }
                    if (!empty($data['cccd']) && $candidate->cccd && $candidate->cccd !== $data['cccd']) {
                        return ['status' => 'error', 'message' => "CCCD không khớp với hồ sơ trúng tuyển '{$data['admission_code']}'."];
                    }
                } elseif (!empty($data['cccd'])) {
                    $candidate = AdmissionCandidate::where('cccd', $data['cccd'])->first();
                }

                if (!$candidate) {
                    return ['status' => 'error', 'message' => 'Không khớp hồ sơ trúng tuyển bằng admission_code hoặc CCCD. Không tạo sinh viên.'];
                }

                if ($candidate->status !== 'admitted' || $candidate->student_id) {
                    return ['status' => 'skipped', 'message' => "Hồ sơ trúng tuyển '{$candidate->admission_code}' không ở trạng thái chờ nhập học."];
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
                    'email'             => $schoolEmail,
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
                    // Cha/mẹ + liên hệ khẩn cấp — nguồn DUY NHẤT giờ là Excel nhập học thật
                    // (trường chỉ thực sự thu thông tin này lúc làm thủ tục nhập học trực
                    // tiếp), không còn thu lúc giữ chỗ online nữa.
                    'father_name'            => $data['father_name'] ?? null,
                    'father_birth_year'      => $data['father_birth_year'] ?? null,
                    'father_job'             => $data['father_job'] ?? null,
                    'father_phone'           => $data['father_phone'] ?? null,
                    'mother_name'            => $data['mother_name'] ?? null,
                    'mother_birth_year'      => $data['mother_birth_year'] ?? null,
                    'mother_job'             => $data['mother_job'] ?? null,
                    'mother_phone'           => $data['mother_phone'] ?? null,
                    'parent_address'         => $data['parent_address'] ?? null,
                    'emergency_contact_name'         => $data['emergency_contact_name'] ?? null,
                    'emergency_contact_phone'        => $data['emergency_contact_phone'] ?? null,
                    'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                ]);

                $linkedNote = '';
                $candidate->update([
                    'status'      => 'enrolled',
                    'student_id'  => $student->id,
                    'enrolled_at' => now(),
                ]);

                DormReservation::where('admission_candidate_id', $candidate->id)
                    ->update(['student_code' => $data['student_code']]);

                $linkedNote = " Đã liên kết hồ sơ trúng tuyển ({$candidate->admission_code}).";

                // Auto-tạo registration nếu có hồ sơ giữ chỗ đang hoạt động. Không còn
                // lọc whereNotNull('father_name') như trước — từ khi bỏ Bước 2 "Thông
                // tin người thân" khỏi FreshmanReservationPage, dorm_reservations mới
                // sẽ luôn có father_name = null (không thu nữa), lọc theo cột đó sẽ
                // chặn auto-tạo registration cho MỌI hồ sơ mới.
                $fullReservation = DormReservation::where('admission_candidate_id', $candidate->id)
                    ->whereIn('status', ['submitted', 'approved', 'waitlisted'])
                    ->latest()
                    ->first();

                if ($fullReservation) {
                    $period = RegistrationPeriod::find($fullReservation->registration_period_id);
                    // Hồ sơ giữ chỗ đã được admin duyệt trước đó thì đơn lưu trú tạo ra
                    // không cần duyệt lại lần nữa — chuyển thẳng sang bước phân phòng.
                    $isPreApproved = $fullReservation->status === 'approved';
                    // Nguồn cha/mẹ ưu tiên: (a) dorm_reservation nếu có sẵn (dữ liệu lịch
                    // sử — hồ sơ giữ chỗ tạo TRƯỚC khi bỏ Bước 2, lúc đó còn thu trực tiếp
                    // lúc giữ chỗ); (b) nếu không có (hồ sơ mới, không còn thu ở bước giữ
                    // chỗ) thì lấy từ $student — vừa được chính Excel bulkEnroll() này ghi
                    // 12 cột cha/mẹ+khẩn cấp ở Student::create() phía trên, cùng 1 lượt xử
                    // lý nên $student chắc chắn đã có dữ liệu nếu Excel cung cấp.
                    // Liên hệ khẩn cấp luôn lấy từ $student vì dorm_reservations chưa bao
                    // giờ có 3 cột này (chỉ thêm sau này cho students/registrations).
                    $familySource = $fullReservation->father_name ? $fullReservation : $student;
                    $reg = Registration::create([
                        'student_id'             => $student->id,
                        'registration_period_id' => $fullReservation->registration_period_id,
                        'registration_type'      => 'new',
                        'semester'               => $period?->semester,
                        'school_year'            => $period?->school_year,
                        'stay_from_date'         => $period?->stay_start_date?->format('Y-m-d'),
                        'stay_to_date'           => $period?->stay_end_date?->format('Y-m-d'),
                        'father_name'            => $familySource->father_name,
                        'father_birth_year'      => $familySource->father_birth_year,
                        'father_job'             => $familySource->father_job,
                        'father_phone'           => $familySource->father_phone,
                        'mother_name'            => $familySource->mother_name,
                        'mother_birth_year'      => $familySource->mother_birth_year,
                        'mother_job'             => $familySource->mother_job,
                        'mother_phone'           => $familySource->mother_phone,
                        'parent_address'         => $familySource->parent_address,
                        'emergency_contact_name'         => $student->emergency_contact_name,
                        'emergency_contact_phone'        => $student->emergency_contact_phone,
                        'emergency_contact_relationship' => $student->emergency_contact_relationship,
                        'commitment_confirm'     => $fullReservation->commitment_confirm ?? true,
                        'status'                 => $isPreApproved ? 'approved' : 'submitted',
                        'approved_at'            => $isPreApproved ? now() : null,
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

                    // Hồ sơ giữ chỗ đã duyệt sẵn -> đơn nội trú tạo ra cũng
                    // approved luôn, sinh viên chỉ còn chờ phân phòng.
                    if ($isPreApproved) {
                        app(StudentNotificationService::class)->notifyStudent(
                            $student,
                            'Đơn đăng ký nội trú đã được duyệt',
                            'Đơn đăng ký nội trú KTX của bạn đã được duyệt. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
                            'registration_approved',
                            $reg->id,
                            queue: true,
                        );
                    }
                }

                // Gửi email thông báo — queue vì đang chạy trong vòng lặp import hàng loạt,
                // tránh chặn request chờ gửi email lần lượt từng dòng trong file.
                if ($student->email) {
                    try {
                        Mail::to($student->email)->queue(new StudentEnrolledMail($student));
                    } catch (\Throwable $e) {
                        Log::error('Gửi email thông báo thất bại', [
                            'type'       => 'student_enrolled',
                            'student_id' => $student->id,
                            'email'      => $student->email,
                            'error'      => $e->getMessage(),
                        ]);
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
