<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use Illuminate\Http\Request;
use App\Models\Registration;
use App\Models\Account;
use App\Models\Student;
use App\Models\Room;
use App\Models\Bed;
use App\Helpers\StorageHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RegistrationController extends Controller
{
    public function index()
    {
        $registrations = Registration::with(['student', 'student.account'])->get();
        
        // Ánh xạ dữ liệu để bổ sung email
        return $registrations->map(function ($registration) {
            $formData = null;
            if (!empty($registration->form_data)) {
                $decoded = json_decode($registration->form_data, true);
                if (is_array($decoded)) {
                    $formData = $decoded;
                }
            }

            return [
                'id' => $registration->id,
                'student_id' => $registration->student_id,
                'email' => $registration->student?->email ?? $registration->student?->account?->email ?? '',
                'formData' => $formData,
                'status' => $registration->status,
                'semester' => $registration->semester,
                'cccd_front_url' => StorageHelper::getPublicUrl($registration->cccd_front_url),
                'cccd_back_url' => StorageHelper::getPublicUrl($registration->cccd_back_url),
                'father_name' => $registration->father_name,
                'father_phone' => $registration->father_phone,
                'father_job' => $registration->father_job,
                'mother_name' => $registration->mother_name,
                'mother_phone' => $registration->mother_phone,
                'mother_job' => $registration->mother_job,
                'parent_address' => $registration->parent_address,
                'stay_from_date' => $registration->stay_from_date,
                'stay_to_date' => $registration->stay_to_date,
                'commitment_confirm' => $registration->commitment_confirm,
                'reason' => $registration->reason,
                'assigned_room_id' => $registration->assigned_room_id,
                'assigned_bed_id' => $registration->assigned_bed_id,
                'note' => $registration->note,
                'created_at' => $registration->created_at,
                'student' => $registration->student,
            ];
        });
    }

    public function store(StoreRegistrationRequest $request)
    {
        $account = Account::where('email', $request->email)->first();

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy user'], 404);
        }

        $currentStudent = $account->student_id ? Student::find($account->student_id) : null;
        $data = $request->validated();

        // Handle file uploads with Railway volume support
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = 'avatar_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = 'students/avatar';
            
            if (StorageHelper::isRailwayWithVolume()) {
                // Save to Railway volume
                $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
                $fullDir = $volumePath . '/' . $path;
                
                // Create directory if it doesn't exist
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
                
                $file->move($fullDir, $filename);
                $data['avatar'] = $path . '/' . $filename;
            } else {
                // Local development
                $data['avatar'] = $file->store($path, 'public');
            }
        }

        if ($request->hasFile('cccd_front')) {
            $file = $request->file('cccd_front');
            $filename = 'cccd_front_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = 'registrations/cccd';
            
            if (StorageHelper::isRailwayWithVolume()) {
                $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
                $fullDir = $volumePath . '/' . $path;
                
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
                
                $file->move($fullDir, $filename);
                $data['cccd_front_url'] = $path . '/' . $filename;
            } else {
                $data['cccd_front_url'] = $file->store($path, 'public');
            }
        }

        if ($request->hasFile('cccd_back')) {
            $file = $request->file('cccd_back');
            $filename = 'cccd_back_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = 'registrations/cccd';
            
            if (StorageHelper::isRailwayWithVolume()) {
                $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
                $fullDir = $volumePath . '/' . $path;
                
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
                
                $file->move($fullDir, $filename);
                $data['cccd_back_url'] = $path . '/' . $filename;
            } else {
                $data['cccd_back_url'] = $file->store($path, 'public');
            }
        }

        try {
            $result = DB::transaction(function () use ($account, $currentStudent, $data) {
                $studentPayload = [
                    'student_code' => $data['student_code'],
                    'avatar' => $data['avatar'] ?? optional($currentStudent)->avatar,
                    'full_name' => $data['full_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'gender' => $data['gender'],
                    'class_name' => $data['class_name'],
                    'faculty' => $data['faculty'],
                    'course_year' => $data['course_year'],
                    'phone' => $data['phone'],
                    'email' => $account->email,
                    'cccd' => $data['cccd'],
                    'cccd_issued_date' => $data['cccd_issued_date'],
                    'cccd_issued_place' => $data['cccd_issued_place'],
                    'nationality' => $data['nationality'],
                    'ethnicity' => $data['ethnicity'],
                    'religion' => $data['religion'],
                    'permanent_address' => $data['permanent_address'],
                    'status' => 'active',
                ];

                if ($currentStudent) {
                    $currentStudent->update($studentPayload);
                    $student = $currentStudent;
                } else {
                    $student = Student::create($studentPayload);
                    $account->student_id = $student->id;
                    $account->save();
                }

                $account->student_code = $data['student_code'];
                $account->save();

                $hasPendingSameSemester = Registration::where('student_id', $student->id)
                    ->where('semester', $data['semester'])
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->exists();

                if ($hasPendingSameSemester) {
                    throw new RuntimeException('DUPLICATE_PENDING_REGISTRATION');
                }

                // Nếu đã có đơn bị từ chối trước đó cho sinh viên và học kỳ này,
                // hãy cập nhật bản ghi đó thay vì tạo mới. Cách này giữ lịch sử
                // nhưng vẫn tái sử dụng hàng bị từ chối cho lần nộp lại.
                $existingRejected = Registration::where('student_id', $student->id)
                    ->where('semester', $data['semester'])
                    ->where('status', 'rejected')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                $registrationPayload = [
                    'student_id' => $student->id,
                    'cccd_front_url' => $data['cccd_front_url'] ?? null,
                    'cccd_back_url' => $data['cccd_back_url'] ?? null,
                    'semester' => $data['semester'],
                    // Lưu snapshot dữ liệu form để giữ lịch sử độc lập với bảng `students`
                    'form_data' => json_encode([
                        'mssv' => $data['student_code'] ?? null,
                        'fullName' => $data['full_name'] ?? null,
                        'birthDate' => $data['date_of_birth'] ?? null,
                        'gender' => $data['gender'] ?? null,
                        'class' => $data['class_name'] ?? null,
                        'department' => $data['faculty'] ?? null,
                        'nationality' => $data['nationality'] ?? null,
                        'ethnicity' => $data['ethnicity'] ?? null,
                        'religion' => $data['religion'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'cccd' => $data['cccd'] ?? null,
                        'cccdIssueDate' => $data['cccd_issued_date'] ?? null,
                        'cccdIssuePlace' => $data['cccd_issued_place'] ?? null,
                        'address' => $data['permanent_address'] ?? null,
                        'father_name' => $data['father_name'] ?? null,
                        'father_phone' => $data['father_phone'] ?? null,
                        'father_job' => $data['father_job'] ?? null,
                        'mother_name' => $data['mother_name'] ?? null,
                        'mother_phone' => $data['mother_phone'] ?? null,
                        'mother_job' => $data['mother_job'] ?? null,
                        'familyContactAddress' => $data['parent_address'] ?? null,
                    ]),
                    'status' => 'pending',
                    'father_name' => $data['father_name'] ?? ($data['parent_name'] ?? ''),
                    'father_birth_year' => $data['father_birth_year'] ?? '',
                    'father_job' => $data['father_job'] ?? '',
                    'father_phone' => $data['father_phone'] ?? ($data['parent_phone'] ?? ''),
                    'mother_name' => $data['mother_name'] ?? '',
                    'mother_birth_year' => $data['mother_birth_year'] ?? '',
                    'mother_job' => $data['mother_job'] ?? '',
                    'mother_phone' => $data['mother_phone'] ?? '',
                    'parent_address' => $data['parent_address'] ?? ($data['permanent_address'] ?? ''),
                    'stay_from_date' => $data['stay_from_date'] ?? now()->toDateString(),
                    'stay_to_date' => $data['stay_to_date'] ?? now()->toDateString(),
                    'commitment_confirm' => $data['commitment_confirm'] ?? false,
                ];

                // Nếu đã có đơn bị từ chối trước đó cho sinh viên và học kỳ này,
                // hãy giữ bản bị từ chối nguyên vẹn (lưu lịch sử) và tạo một
                // bản ghi mới với trạng thái 'pending' cho lần nộp lại.
                if ($existingRejected) {
                    if (isset($data['cccd_front_url'])) {
                        $registrationPayload['cccd_front_url'] = $data['cccd_front_url'];
                    }

                    if (isset($data['cccd_back_url'])) {
                        $registrationPayload['cccd_back_url'] = $data['cccd_back_url'];
                    }

                    $registration = Registration::create($registrationPayload);
                } else {
                    $registration = Registration::create($registrationPayload);
                }

                return [
                    'student' => $student,
                    'registration' => $registration,
                ];
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'DUPLICATE_PENDING_REGISTRATION') {
                return response()->json([
                    'message' => 'Bạn đã có đơn chờ duyệt cho học kỳ này'
                ], 409);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Đăng ký thành công',
            'data' => $result
        ], 201);
    }


    public function getMyRegistration(Request $request)
    {
        $email = $request->query('email');
        $semester = $request->query('semester', '2024-2025');

        $account = Account::where('email', $email)->first();

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy user'], 404);
        }

        // Nếu tài khoản chưa liên kết với sinh viên thì không có
        // đơn đăng ký nào để trả về. Tránh truy vấn student_id NULL
        // vì có thể vô tình khớp các đơn có id NULL.
        if (!$account->student_id) {
            return response()->json(null);
        }

        $registration = Registration::with(['student', 'student.account'])
            ->where('student_id', $account->student_id)
            ->where('semester', $semester)
            ->latest('id')
            ->first();

        if (!$registration) {
            return response()->json(null);
        }

        return response()->json([
            'id' => $registration->id,
            'student_id' => $registration->student_id,
            'formData' => (!empty($registration->form_data) ? json_decode($registration->form_data, true) : null),
            'email' => $registration->student?->email ?? $email ?? '',
            'status' => $registration->status,
            'semester' => $registration->semester,
            'cccd_front_url' => StorageHelper::getPublicUrl($registration->cccd_front_url),
            'cccd_back_url' => StorageHelper::getPublicUrl($registration->cccd_back_url),
            'father_name' => $registration->father_name,
            'father_phone' => $registration->father_phone,
            'father_job' => $registration->father_job,
            'mother_name' => $registration->mother_name,
            'mother_phone' => $registration->mother_phone,
            'mother_job' => $registration->mother_job,
            'parent_address' => $registration->parent_address,
            'stay_from_date' => $registration->stay_from_date,
            'stay_to_date' => $registration->stay_to_date,
            'commitment_confirm' => $registration->commitment_confirm,
            'reason' => $registration->reason,
            'assigned_room_id' => $registration->assigned_room_id,
            'assigned_bed_id' => $registration->assigned_bed_id,
            'note' => $registration->note,
            'created_at' => $registration->created_at,
            'student' => $registration->student,
        ]);
    }


    public function approve($id, Request $request)
    {
        $registration = Registration::find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $registration->status = 'approved';
        $registration->save();

        return response()->json([
            'message' => 'Đã duyệt'
        ]);
    }

    // Quản trị: liệt kê phòng với số giường cơ bản
    public function getRooms()
    {
        $rooms = Room::with('beds')->get();

        return $rooms->map(function ($room) {
            $totalBeds = $room->beds->count();
            $availableBeds = $room->beds->where('status', 'empty')->count();

            return [
                'id' => $room->id,
                'building_code' => $room->building_code,
                'room_number' => $room->room_number,
                'totalBeds' => $totalBeds,
                'availableBeds' => $availableBeds,
                // backend không có cột giới tính; frontend có thể xem đây là trường tùy chọn
                'gender' => $room->gender ?? null,
            ];
        })->values();
    }

    // Quản trị: gán phòng cho đơn đăng ký
    public function assignRoom($id, Request $request)
    {
        $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
        ]);

        $registration = Registration::find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $registration->assigned_room_id = $request->room_id;
        $registration->save();

        return response()->json(['message' => 'Đã phân phòng']);
    }

    // Sinh viên: chọn giường theo email (frontend gọi theo email)
    public function selectBed(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'bed_id' => 'required|integer|exists:beds,id',
        ]);

        $account = Account::where('email', $request->email)->first();

        if (!$account || !$account->student_id) {
            return response()->json(['message' => 'Không tìm thấy user hoặc chưa liên kết sinh viên'], 404);
        }

        $registration = Registration::where('student_id', $account->student_id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn đăng ký'], 404);
        }

        $bed = Bed::find($request->bed_id);
        if (!$bed) {
            return response()->json(['message' => 'Giường không tồn tại'], 404);
        }

        // Đánh dấu giường là đã có người ở và gắn vào đơn đăng ký
        $bed->status = 'occupied';
        $bed->save();

        $registration->assigned_bed_id = $bed->id;
        $registration->assigned_room_id = $bed->room_id;
        $registration->save();

        return response()->json(['message' => 'Đã chọn giường']);
    }

    public function reject($id, Request $request)
    {
        $request->validate([
            'rejectionReason' => 'required|string|max:500',
        ]);

        $registration = Registration::find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $registration->status = 'rejected';
        $registration->reason = $request->rejectionReason;
        $registration->save();

        return response()->json([
            'message' => 'Đã từ chối'
        ]);
    }

    public function show($id)
    {
        Log::info("RegistrationController.show($id) - fetching registration with id: $id");
        
        $registration = Registration::with(['student', 'student.account'])->find($id);
        
        Log::info("RegistrationController.show($id) - found registration", [
            'id' => $registration?->id,
            'status' => $registration?->status,
            'student_id' => $registration?->student_id,
        ]);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy'], 404);
        }

        $formData = null;
        if (!empty($registration->form_data)) {
            $decoded = json_decode($registration->form_data, true);
            if (is_array($decoded)) {
                $formData = $decoded;
            }
        }

        return response()->json([
            'id' => $registration->id,
            'student_id' => $registration->student_id,
            'formData' => $formData,
            'email' => $registration->student?->email ?? $registration->student?->account?->email ?? '',
            'status' => $registration->status,
            'semester' => $registration->semester,
            'cccd_front_url' => StorageHelper::getPublicUrl($registration->cccd_front_url),
            'cccd_back_url' => StorageHelper::getPublicUrl($registration->cccd_back_url),
            'avatarUrl' => StorageHelper::getPublicUrl($registration->student?->avatar),
            'father_name' => $registration->father_name,
            'father_phone' => $registration->father_phone,
            'father_job' => $registration->father_job,
            'mother_name' => $registration->mother_name,
            'mother_phone' => $registration->mother_phone,
            'mother_job' => $registration->mother_job,
            'parent_address' => $registration->parent_address,
            'stay_from_date' => $registration->stay_from_date,
            'stay_to_date' => $registration->stay_to_date,
            'commitment_confirm' => $registration->commitment_confirm,
            'reason' => $registration->reason,
            'assigned_room_id' => $registration->assigned_room_id,
            'assigned_bed_id' => $registration->assigned_bed_id,
            'note' => $registration->note,
            'created_at' => $registration->created_at,
            'student' => $registration->student,
        ]);
    }

    //Tìm hiểu kỹ
    /**
     * Get all registrations for a specific student and semester (for history)
     * Used by admin to view student's submission history
     */
    public function getRegistrationHistory($email, $semester)
    {
        Log::info("RegistrationController.getRegistrationHistory - email: $email, semester: $semester");
        
        $account = Account::where('email', $email)->first();

        if (!$account || !$account->student_id) {
            Log::info("RegistrationController.getRegistrationHistory - student not found");
            return response()->json([]);
        }

        $registrations = Registration::with(['student', 'student.account'])
            ->where('student_id', $account->student_id)
            ->where('semester', $semester)
            ->orderBy('id', 'asc')  // chronological order
            ->get();

        Log::info("RegistrationController.getRegistrationHistory - found registrations", [
            'count' => $registrations->count(),
            'student_id' => $account->student_id,
        ]);

        return $registrations->map(function ($registration) {
            $formData = null;
            if (!empty($registration->form_data)) {
                $decoded = json_decode($registration->form_data, true);
                if (is_array($decoded)) {
                    $formData = $decoded;
                }
            }

            return [
                'id' => $registration->id,
                'student_id' => $registration->student_id,
                'email' => $registration->student?->email ?? '',
                'formData' => $formData,
                'status' => $registration->status,
                'semester' => $registration->semester,
                'cccd_front_url' => StorageHelper::getPublicUrl($registration->cccd_front_url),
                'cccd_back_url' => StorageHelper::getPublicUrl($registration->cccd_back_url),
                'father_name' => $registration->father_name,
                'father_phone' => $registration->father_phone,
                'father_job' => $registration->father_job,
                'mother_name' => $registration->mother_name,
                'mother_phone' => $registration->mother_phone,
                'mother_job' => $registration->mother_job,
                'parent_address' => $registration->parent_address,
                'stay_from_date' => $registration->stay_from_date,
                'stay_to_date' => $registration->stay_to_date,
                'commitment_confirm' => $registration->commitment_confirm,
                'reason' => $registration->reason,
                'assigned_room_id' => $registration->assigned_room_id,
                'assigned_bed_id' => $registration->assigned_bed_id,
                'note' => $registration->note,
                'created_at' => $registration->created_at,
                'student' => $registration->student,
            ];
        });
    }
}