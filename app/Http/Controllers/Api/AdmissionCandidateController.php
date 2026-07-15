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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Mail\StudentEnrolledMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AdmissionCandidateController extends Controller
{
    private const CONVERTIBLE_RESERVATION_STATUSES = ['approved'];

    public function index(Request $request): JsonResponse
    {
        $query = AdmissionCandidate::with('student')
            ->withCount('dormReservations')
            ->orderByRaw('COALESCE(enrolled_at, created_at) DESC')
            ->orderBy('full_name')
            ->orderBy('admission_code');

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
            'A1' => 'admission_code',
            'B1' => 'student_code',
            'C1' => 'full_name',
            'D1' => 'date_of_birth',
            'E1' => 'class_name',
            'F1' => 'school_email',
            'G1' => 'gender',
            'H1' => 'faculty',
            'I1' => 'course_year',
            'J1' => 'current_year',
            'K1' => 'phone',
            'L1' => 'cccd',
            'M1' => 'cccd_issued_date',
            'N1' => 'cccd_issued_place',
            'O1' => 'nationality',
            'P1' => 'ethnicity',
            'Q1' => 'religion',
            'R1' => 'permanent_address',
            // Cha/mẹ + liên hệ khẩn cấp — nguồn duy nhất từ đây (trường thu lúc làm thủ
            // tục nhập học trực tiếp), không còn thu lúc giữ chỗ online nữa.
            'S1' => 'father_name',
            'T1' => 'father_birth_year',
            'U1' => 'father_job',
            'V1' => 'father_phone',
            'W1' => 'mother_name',
            'X1' => 'mother_birth_year',
            'Y1' => 'mother_job',
            'Z1' => 'mother_phone',
            'AA1' => 'parent_address',
            'AB1' => 'emergency_contact_name',
            'AC1' => 'emergency_contact_phone',
            'AD1' => 'emergency_contact_relationship',
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
        $sheet->setCellValue('A2', '01_TT_XTS_GBTT_00427');
        $sheet->setCellValue('B2', 'DH52507001');
        $sheet->setCellValue('C2', 'Nguyễn Văn An');
        $sheet->setCellValue('D2', '2006-05-15');
        $sheet->setCellValue('E2', 'D26CNTT01');
        $sheet->setCellValue('F2', 'nguyenvanan26@stu.edu.vn');
        $sheet->setCellValue('G2', 'male');
        $sheet->setCellValue('H2', 'Công nghệ thông tin');
        $sheet->setCellValue('I2', '2026-2030');
        $sheet->setCellValue('J2', 1);
        $sheet->setCellValue('K2', '0901234567');
        $sheet->setCellValue('L2', '087654321001');
        $sheet->setCellValue('M2', '2021-01-15');
        $sheet->setCellValue('N2', 'Cục CS QLHC về TTXH - Bộ Công an');
        $sheet->setCellValue('O2', 'Việt Nam');
        $sheet->setCellValue('P2', 'Kinh');
        $sheet->setCellValue('Q2', 'Không');
        $sheet->setCellValue('R2', '123 Đường ABC, Phường XYZ, Quận 1, TP.HCM');
        $sheet->setCellValue('S2', 'Nguyễn Văn Bình');
        $sheet->setCellValue('T2', '1975');
        $sheet->setCellValue('U2', 'Nông dân');
        $sheet->setCellValue('V2', '0912345678');
        $sheet->setCellValue('W2', 'Trần Thị Lan');
        $sheet->setCellValue('X2', '1978');
        $sheet->setCellValue('Y2', 'Nội trợ');
        $sheet->setCellValue('Z2', '0912345679');
        $sheet->setCellValue('AA2', '123 Đường ABC, Phường XYZ, Quận 1, TP.HCM');
        $sheet->setCellValue('AB2', 'Nguyễn Thị Hoa');
        $sheet->setCellValue('AC2', '0912345680');
        $sheet->setCellValue('AD2', 'sibling');

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

    public function importCandidatesTemplate(): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Danh sach trung tuyen');

        $headers = [
            'A1' => 'admission_code',
            'B1' => 'full_name',
            'C1' => 'date_of_birth',
            'D1' => 'gender',
            'E1' => 'cccd',
            'F1' => 'phone',
            'G1' => 'email',
            'H1' => 'permanent_address',
            'I1' => 'major_name',
            'J1' => 'course_year',
            'K1' => 'school_year',
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        $sheet->getStyle('A1:K1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '244CB8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->setCellValue('A2', '01_TT_XTS_GBTT_00427');
        $sheet->setCellValue('B2', 'Trần Thị Bích');
        $sheet->setCellValue('C2', '2006-07-22');
        $sheet->setCellValue('D2', 'female');
        $sheet->setCellValueExplicit('E2', '079306002002', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F2', '0912345672', DataType::TYPE_STRING);
        $sheet->setCellValue('G2', 'bichtran@example.com');
        $sheet->setCellValue('H2', 'Số 12, Đường Lê Lợi, Phường 2, TP. Vũng Tàu, Bà Rịa - Vũng Tàu');
        $sheet->setCellValue('I2', 'Công nghệ thông tin');
        $sheet->setCellValue('J2', '2026-2030');
        $sheet->setCellValue('K2', '2026-2027');

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
            'Content-Disposition' => 'attachment; filename="danh_sach_trung_tuyen_mau.xlsx"',
        ]);
    }

    public function importCandidates(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        $spreadsheet = IOFactory::load($request->file('file')->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        if ($highestRow < 2) {
            return response()->json(['message' => 'File không có dữ liệu (chỉ có hàng tiêu đề hoặc rỗng).'], 422);
        }

        if ($highestRow > 1001) {
            return response()->json(['message' => 'Tối đa 1000 dòng mỗi lần import.'], 422);
        }

        $colMap = [];
        for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
            $header = $this->normalizeImportString($sheet->getCell($column . '1')->getFormattedValue());
            if ($header !== null) {
                $colMap[$column] = strtolower($header);
            }
        }

        $required = ['admission_code', 'full_name', 'date_of_birth'];
        $missing = array_diff($required, array_values($colMap));
        if ($missing) {
            return response()->json([
                'message' => 'File danh sách trúng tuyển thiếu cột bắt buộc: ' . implode(', ', $missing) . '.',
            ], 422);
        }

        $summary = ['total' => 0, 'success' => 0, 'skipped' => 0, 'error' => 0];
        $rows = [];
        $seenAdmissionCodes = [];
        $seenCccds = [];

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $data = [];
            foreach ($colMap as $column => $field) {
                $data[$field] = $this->normalizeImportString($sheet->getCell($column . $rowIndex)->getFormattedValue());
            }

            if (empty(array_filter($data, fn ($value) => $value !== null && $value !== ''))) {
                continue;
            }

            $summary['total']++;
            $rowResult = [
                'row' => $rowIndex,
                'admission_code' => $data['admission_code'] ?? null,
                'full_name' => $data['full_name'] ?? null,
            ];

            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $summary['error']++;
                    $label = $field === 'admission_code'
                        ? 'mã trúng tuyển'
                        : ($field === 'full_name' ? 'họ tên' : 'ngày sinh');
                    $rows[] = array_merge($rowResult, [
                        'status' => 'error',
                        'message' => "Thiếu {$label}.",
                    ]);
                    continue 2;
                }
            }

            $admissionCode = $data['admission_code'];
            if (isset($seenAdmissionCodes[$admissionCode])) {
                $summary['error']++;
                $rows[] = array_merge($rowResult, [
                    'status' => 'error',
                    'message' => "Dòng {$rowIndex} trùng admission_code với dòng {$seenAdmissionCodes[$admissionCode]} trong cùng file.",
                ]);
                continue;
            }
            $seenAdmissionCodes[$admissionCode] = $rowIndex;

            $cccd = $data['cccd'] ?? null;
            if ($cccd && isset($seenCccds[$cccd])) {
                $summary['error']++;
                $rows[] = array_merge($rowResult, [
                    'status' => 'error',
                    'message' => "Dòng {$rowIndex} trùng CCCD với dòng {$seenCccds[$cccd]} trong cùng file.",
                ]);
                continue;
            }
            if ($cccd) {
                $seenCccds[$cccd] = $rowIndex;
            }

            $validationMessage = $this->validateImportedCandidateRow($data, $sheet, $rowIndex);
            if ($validationMessage) {
                $summary['error']++;
                $rows[] = array_merge($rowResult, [
                    'status' => 'error',
                    'message' => $validationMessage,
                ]);
                continue;
            }

            $dateOfBirth = $this->parseImportDate($sheet, $colMap, $rowIndex, 'date_of_birth');

            try {
                $result = DB::transaction(function () use ($data, $dateOfBirth, $rowResult) {
                    if (AdmissionCandidate::where('admission_code', $data['admission_code'])->lockForUpdate()->exists()) {
                        return array_merge($rowResult, [
                            'status' => 'error',
                            'message' => 'Mã trúng tuyển đã tồn tại trong hệ thống.',
                        ]);
                    }

                    if (!empty($data['cccd']) && AdmissionCandidate::where('cccd', $data['cccd'])->lockForUpdate()->exists()) {
                        return array_merge($rowResult, [
                            'status' => 'error',
                            'message' => 'CCCD đã tồn tại trong hệ thống.',
                        ]);
                    }

                    AdmissionCandidate::create([
                        'admission_code' => $data['admission_code'],
                        'full_name' => $data['full_name'],
                        'date_of_birth' => $dateOfBirth,
                        'gender' => $this->normalizeGender($data['gender'] ?? null),
                        'cccd' => $data['cccd'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'email' => $data['email'] ?? null,
                        'permanent_address' => $data['permanent_address'] ?? null,
                        'major_name' => $data['major_name'] ?? null,
                        'course_year' => $data['course_year'] ?? null,
                        'school_year' => $data['school_year'] ?? null,
                        'status' => 'admitted',
                    ]);

                    return array_merge($rowResult, [
                        'status' => 'success',
                        'message' => 'Đã tạo hồ sơ trúng tuyển.',
                    ]);
                });
            } catch (QueryException $e) {
                $duplicateMessage = $this->candidateImportDuplicateMessage($e, $data);
                if (!$duplicateMessage) {
                    Log::error('Import danh sách trúng tuyển thất bại khi tạo hồ sơ', [
                        'row' => $rowIndex,
                        'admission_code' => $data['admission_code'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }

                $result = array_merge($rowResult, [
                    'status' => 'error',
                    'message' => $duplicateMessage ?? 'Không thể tạo hồ sơ trúng tuyển. Vui lòng kiểm tra lại dữ liệu.',
                ]);
            }

            $summary[$result['status']]++;
            $rows[] = $result;
        }

        return response()->json(compact('summary', 'rows'));
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

        $required = ['admission_code', 'student_code', 'full_name', 'date_of_birth', 'class_name', 'school_email'];
        $missing  = array_diff($required, array_values($colMap));
        if ($missing) {
            if (in_array('admission_code', $missing, true)) {
                return response()->json([
                    'message' => 'File nhập học thiếu cột bắt buộc admission_code.',
                ], 422);
            }

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
            $rowResult = [
                'row'            => $rowIndex,
                'student_code'   => $data['student_code'] ?? null,
                'admission_code' => $data['admission_code'] ?? null,
                'full_name'      => $data['full_name'] ?? null,
            ];

            foreach ($required as $req) {
                if (empty($data[$req])) {
                    $summary['error']++;
                    $message = $req === 'admission_code' ? 'Thiếu mã trúng tuyển.' : "Thiếu {$req}.";
                    $rows[] = array_merge($rowResult, ['status' => 'error', 'message' => $message]);
                    continue 2;
                }
            }

            $result = DB::transaction(function () use ($data) {
                $schoolEmail = $data['school_email'];
                if (!filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
                    return ['status' => 'error', 'message' => "Email trường '{$schoolEmail}' không hợp lệ."];
                }

                $candidate = AdmissionCandidate::where('admission_code', $data['admission_code'])
                    ->lockForUpdate()
                    ->first();
                if (!$candidate) {
                    return [
                        'status' => 'error',
                        'admission_code' => $data['admission_code'],
                        'student_code' => $data['student_code'],
                        'full_name' => $data['full_name'],
                        'message' => "Không tìm thấy hồ sơ trúng tuyển với mã {$data['admission_code']}.",
                    ];
                }

                $rowIdentity = [
                    'admission_code' => $candidate->admission_code,
                    'student_code' => $data['student_code'],
                    'full_name' => $candidate->full_name,
                ];

                if (!empty($data['cccd']) && $candidate->cccd && $candidate->cccd !== $data['cccd']) {
                    return array_merge($rowIdentity, [
                        'status' => 'error',
                        'message' => 'CCCD không khớp với hồ sơ trúng tuyển.',
                    ]);
                }

                if ($candidate->status === 'enrolled' || $candidate->student_id) {
                    return array_merge($rowIdentity, ['status' => 'skipped', 'message' => 'Sinh viên đã được xử lý nhập học trước đó.']);
                }

                if ($candidate->status === 'cancelled') {
                    return array_merge($rowIdentity, ['status' => 'skipped', 'message' => 'Hồ sơ trúng tuyển đã bị hủy.']);
                }

                if ($candidate->status !== 'admitted') {
                    return array_merge($rowIdentity, ['status' => 'skipped', 'message' => "Hồ sơ trúng tuyển '{$candidate->admission_code}' không ở trạng thái chờ nhập học."]);
                }

                $fullReservation = $this->findConvertibleReservationForCandidate($candidate->id, true);
                if (!$fullReservation) {
                    $hasConvertedReservation = DormReservation::where('admission_candidate_id', $candidate->id)
                        ->where('status', 'converted')
                        ->lockForUpdate()
                        ->first();

                    return array_merge($rowIdentity, [
                        'status' => 'skipped',
                        'message' => $hasConvertedReservation
                            ? 'Hồ sơ giữ chỗ đã được chuyển thành đơn đăng ký nội trú trước đó.'
                            : $this->reservationNotConvertibleMessage($candidate->id, true),
                    ]);
                }

                // Skip if MSSV already exists
                if (Student::where('student_code', $data['student_code'])->lockForUpdate()->first()) {
                    return array_merge($rowIdentity, ['status' => 'skipped', 'message' => "MSSV '{$data['student_code']}' đã tồn tại trong hệ thống."]);
                }

                // Check CCCD collision
                if (!empty($data['cccd']) && Student::where('cccd', $data['cccd'])->lockForUpdate()->first()) {
                    return array_merge($rowIdentity, ['status' => 'error', 'message' => "CCCD '{$data['cccd']}' đã thuộc về sinh viên khác."]);
                }

                // Check school email collision.
                if (Student::where('email', $schoolEmail)->lockForUpdate()->first()) {
                    return array_merge($rowIdentity, ['status' => 'error', 'message' => "Email trường '{$schoolEmail}' đã thuộc về sinh viên khác."]);
                }

                $existingRegistration = Registration::where('registration_period_id', $fullReservation->registration_period_id)
                    ->where('status', '!=', 'rejected')
                    ->where(function ($q) use ($data, $candidate) {
                        $q->whereHas('student', fn ($studentQuery) => $studentQuery->where('student_code', $data['student_code']));
                        if ($candidate->student_id) {
                            $q->orWhere('student_id', $candidate->student_id);
                        }
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($existingRegistration) {
                    return array_merge($rowIdentity, ['status' => 'skipped', 'message' => 'Sinh viên đã có đơn đăng ký nội trú trong đợt này.']);
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

                $linkedNote = " Đã liên kết hồ sơ trúng tuyển ({$candidate->admission_code}).";

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
                    'student_code'              => $data['student_code'],
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

                return array_merge($rowIdentity, [
                    'status'       => 'success',
                    'student_code' => $data['student_code'],
                    'message'      => "Đã tạo sinh viên.{$linkedNote}" . ($student->email ? ' Email đã gửi.' : ''),
                ]);
            });

            $summary[$result['status']]++;
            $rows[] = array_merge($rowResult, $result);
        }

        return response()->json(compact('summary', 'rows'));
    }

    private function findConvertibleReservationForCandidate(int $candidateId, bool $lock = false): ?DormReservation
    {
        $activePeriod = $this->findActiveAdmissionPeriod($lock);
        $query = DormReservation::where('admission_candidate_id', $candidateId)
            ->whereIn('status', self::CONVERTIBLE_RESERVATION_STATUSES)
            ->whereNull('converted_registration_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($activePeriod) {
            $activeReservation = (clone $query)
                ->where('registration_period_id', $activePeriod->id)
                ->orderByDesc('approved_at')
                ->orderByDesc('submitted_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($activeReservation) {
                return $activeReservation;
            }
        }

        return $query
            ->orderByDesc('approved_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function normalizeImportString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeGender(?string $gender): ?string
    {
        if ($gender === null || trim($gender) === '') {
            return null;
        }

        $value = mb_strtolower(trim($gender));

        return match ($value) {
            'male', 'nam' => 'male',
            'female', 'nữ', 'nu' => 'female',
            default => null,
        };
    }

    private function findImportColumn(array $colMap, string $field): ?string
    {
        foreach ($colMap as $column => $mappedField) {
            if ($mappedField === $field) {
                return $column;
            }
        }

        return null;
    }

    private function parseImportDate($sheet, array $colMap, int $rowIndex, string $field): ?string
    {
        $column = $this->findImportColumn($colMap, $field);
        if (!$column) {
            return null;
        }

        $cell = $sheet->getCell($column . $rowIndex);
        $rawValue = $cell->getValue();
        $formattedValue = $this->normalizeImportString($cell->getFormattedValue());

        try {
            if (is_numeric($rawValue)) {
                return ExcelDate::excelToDateTimeObject((float) $rawValue)->format('Y-m-d');
            }

            if ($formattedValue === null) {
                return null;
            }

            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
                $date = Carbon::createFromFormat($format, $formattedValue);
                if ($date && $date->format($format) === $formattedValue) {
                    return $date->format('Y-m-d');
                }
            }

            return Carbon::parse($formattedValue)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function validateImportedCandidateRow(array $data, $sheet, int $rowIndex): ?string
    {
        $colMap = [];
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
            $header = $this->normalizeImportString($sheet->getCell($column . '1')->getFormattedValue());
            if ($header !== null) {
                $colMap[$column] = strtolower($header);
            }
        }

        $dateOfBirth = $this->parseImportDate($sheet, $colMap, $rowIndex, 'date_of_birth');
        if (!$dateOfBirth) {
            return 'Ngày sinh không hợp lệ.';
        }

        if (Carbon::parse($dateOfBirth)->isFuture()) {
            return 'Ngày sinh không được là ngày tương lai.';
        }

        $maxRules = [
            'admission_code' => 100,
            'full_name' => 191,
            'cccd' => 20,
            'phone' => 20,
            'email' => 191,
            'major_name' => 191,
            'course_year' => 20,
            'school_year' => 20,
        ];

        foreach ($maxRules as $field => $max) {
            if (!empty($data[$field]) && mb_strlen($data[$field]) > $max) {
                return "{$field} vượt quá {$max} ký tự.";
            }
        }

        if (!empty($data['gender']) && $this->normalizeGender($data['gender']) === null) {
            return 'Giới tính không hợp lệ. Chỉ nhận male/female hoặc Nam/Nữ.';
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Email không hợp lệ.';
        }

        return null;
    }

    private function candidateImportDuplicateMessage(QueryException $e, array $data): ?string
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (string) ($e->errorInfo[1] ?? '');
        $message = mb_strtolower($e->getMessage());
        $isDuplicate = in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '19'], true)
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique');

        if (!$isDuplicate) {
            return null;
        }

        if (
            str_contains($message, 'admission_code')
            || AdmissionCandidate::where('admission_code', $data['admission_code'] ?? null)->exists()
        ) {
            return 'Mã trúng tuyển đã tồn tại trong hệ thống.';
        }

        if (
            !empty($data['cccd'])
            && (
                str_contains($message, 'cccd')
                || AdmissionCandidate::where('cccd', $data['cccd'])->exists()
            )
        ) {
            return 'CCCD đã tồn tại trong hệ thống.';
        }

        return null;
    }

    private function findActiveAdmissionPeriod(bool $lock = false): ?RegistrationPeriod
    {
        $query = RegistrationPeriod::where('status', 'active')
            ->where('allow_admission_candidates', true)
            ->latest('created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function reservationNotConvertibleMessage(int $candidateId, bool $lock = false): string
    {
        $query = DormReservation::where('admission_candidate_id', $candidateId)
            ->orderByDesc('submitted_at')
            ->orderByDesc('approved_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $reservation = $query->first();

        if ($reservation?->status === 'approved' && $reservation->converted_registration_id) {
            return 'Hồ sơ giữ chỗ đã được chuyển thành đơn đăng ký nội trú trước đó.';
        }

        return match ($reservation?->status) {
            'submitted' => 'Hồ sơ giữ chỗ chưa được duyệt.',
            'waitlisted' => 'Hồ sơ đang trong danh sách chờ, chưa đủ điều kiện chuyển thành đơn nội trú.',
            'rejected' => 'Hồ sơ giữ chỗ không được duyệt.',
            'cancelled' => 'Hồ sơ giữ chỗ đã bị hủy.',
            'expired' => 'Hồ sơ giữ chỗ đã hết hiệu lực.',
            'converted' => 'Hồ sơ giữ chỗ đã được chuyển thành đơn đăng ký nội trú trước đó.',
            default => 'Sinh viên không có hồ sơ giữ chỗ KTX được duyệt.',
        };
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
