<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use Illuminate\Http\Request;
use App\Models\Registration;
use App\Models\Occupancy;
use App\Models\Account;
use App\Models\Student;
use App\Models\Room;
use App\Models\Bed;
use App\Models\Floor;
use App\Helpers\StorageHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RegistrationController extends Controller
{
    /**
     * Helper to get the correct URL based on environment
     */
    private function getImageUrl($path)
    {
        if (empty($path)) {
            return null;
        }
        
        // If it's already a full URL, return as-is
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        
        $cleanPath = ltrim($path, '/');
        
        // Check if we're in production (Railway)
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';
        
        if ($isProduction) {
            // Railway: use /api/storage/
            return url('/api/storage/' . $cleanPath);
        }
        
        // Local development: use /storage/
        return url('/storage/' . $cleanPath);
    }

    private function formatRegistration(Registration $registration, ?string $emailFallback = null): array
    {
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
            'email' => $registration->student?->email ?? $emailFallback ?? '',
            'formData' => $formData,
            'status' => $registration->status,
            'semester' => $registration->semester,
            'cccd_front_url' => $this->getImageUrl($registration->cccd_front_url),
            'cccd_back_url' => $this->getImageUrl($registration->cccd_back_url),
            'avatarUrl' => $this->getImageUrl($registration->avatar_url ?? $registration->student?->avatar),
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
            'assigned_room_id' => $registration->occupancy?->room_id,
            'assigned_bed_id' => $registration->occupancy?->bed_id,
            'bed_approval_status' => $this->formatBedApprovalStatus($registration->occupancy?->status),
            'note' => $registration->note,
            'created_at' => $registration->created_at,
            'student' => $registration->student,
        ];
    }

    private function formatBedApprovalStatus(?string $status): ?string
    {
        $normalized = strtolower(trim((string) $status));

        if ($normalized === 'occupied' || $normalized === 'active') {
            return 'approved';
        }

        if ($normalized === 'pending' || $normalized === 'rejected') {
            return $normalized;
        }

        return null;
    }

    private function recordRoomChange(?Occupancy $occupancy, ?int $oldRoomId, ?int $oldBedId, ?int $newRoomId, ?int $newBedId, ?string $reason = null): void
    {
        if (!$occupancy || ($oldRoomId === $newRoomId && $oldBedId === $newBedId)) {
            return;
        }

        DB::table('room_change_log')->insert([
            'occupancy_id' => $occupancy->id,
            'old_room_id' => $oldRoomId,
            'old_bed_id' => $oldBedId,
            'new_room_id' => $newRoomId,
            'new_bed_id' => $newBedId,
            'transfer_reason' => $reason,
            'transfered_at' => now(),
        ]);
    }
    
    public function index()
    {
        $registrations = Registration::with(['student', 'student.account', 'occupancy'])->get();

        return $registrations->map(function ($registration) {
            return $this->formatRegistration($registration);
        });
    }

    public function store(StoreRegistrationRequest $request)
    {
        $student = Student::where('email', $request->email)->first();

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy user'], 404);
        }

        $account = $student->account;

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy tài khoản'], 404);
        }

        $currentStudent = $student;
        $data = $request->validated();

        // Handle file uploads with Railway volume support
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = 'avatar_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = 'students/avatar';
            
            if (StorageHelper::isRailwayWithVolume()) {
                $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
                $fullDir = $volumePath . '/' . $path;
                
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
                
                $file->move($fullDir, $filename);
                $data['avatar'] = $path . '/' . $filename;
            } else {
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
                // Clean existing avatar if it's a full URL
                $existingAvatar = null;
                if ($currentStudent && $currentStudent->avatar) {
                    $existingAvatar = $currentStudent->avatar;
                    if (strpos($existingAvatar, '/storage/') !== false) {
                        $parts = explode('/storage/', $existingAvatar, 2);
                        $existingAvatar = $parts[1] ?? $existingAvatar;
                    }
                }
                
                $studentPayload = [
                    'student_code' => $data['student_code'],
                    'avatar' => $data['avatar'] ?? $existingAvatar,
                    'full_name' => $data['full_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'gender' => $data['gender'],
                    'class_name' => $data['class_name'],
                    'faculty' => $data['faculty'],
                    'course_year' => $data['course_year'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
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

                $hasPendingSameSemester = Registration::where('student_id', $student->id)
                    ->where('semester', $data['semester'])
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->exists();

                if ($hasPendingSameSemester) {
                    throw new RuntimeException('DUPLICATE_PENDING_REGISTRATION');
                }

                $existingRejected = Registration::where('student_id', $student->id)
                    ->where('semester', $data['semester'])
                    ->where('status', 'rejected')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                $registrationPayload = [
                    'student_id' => $student->id,
                    'avatar_url' => $data['avatar'] ?? $existingAvatar,
                    'cccd_front_url' => $data['cccd_front_url'] ?? null,
                    'cccd_back_url' => $data['cccd_back_url'] ?? null,
                    'semester' => $data['semester'],
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

        $student = Student::where('email', $email)->first();

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy user'], 404);
        }

        $account = $student->account;

        if (!$account) {
            return response()->json(['message' => 'Không tìm thấy tài khoản'], 404);
        }

        if (!$account->student_id) {
            return response()->json(null);
        }

        $registration = Registration::with(['student', 'student.account', 'occupancy'])
            ->where('student_id', $account->student_id)
            ->where('semester', $semester)
            ->latest('id')
            ->first();

        if (!$registration) {
            return response()->json(null);
        }

        return response()->json($this->formatRegistration($registration, $email));
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

    public function getRooms()
    {
        $rooms = Room::with(['beds', 'floor'])->get();

        return $rooms->map(function ($room) {
            $totalBeds = $room->beds->count();
            $availableBeds = $room->beds->where('status', 'empty')->count();

            return [
                'id' => $room->id,
                'building_code' => $room->floor?->building_code,
                'floor_id' => $room->floor_id,
                'floor_number' => $room->floor?->floor_number,
                'room_number' => $room->room_number,
                'totalBeds' => $totalBeds,
                'availableBeds' => $availableBeds,
                'gender' => $room->gender ?? null,
            ];
        })->values();
    }

    public function assignRoom($id, Request $request)
    {
        $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
        ]);

        $registration = Registration::with('occupancy')->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $occupancy = Occupancy::firstOrNew([
            'student_id' => $registration->student_id,
        ]);

        $oldRoomId = $occupancy->exists ? $occupancy->room_id : null;
        $oldBedId = $occupancy->exists ? $occupancy->bed_id : null;

        if ($occupancy->exists && $occupancy->bed_id) {
            $currentBed = Bed::find($occupancy->bed_id);
            if (!$currentBed || (int) $currentBed->room_id !== (int) $request->room_id) {
                if ($currentBed) {
                    $currentBed->status = 'empty';
                    $currentBed->save();
                }

                $occupancy->bed_id = null;
            }
        }

        $occupancy->registration_id = $registration->id;
        $occupancy->room_id = $request->room_id;
        $occupancy->bed_id = null;
        $occupancy->status = $occupancy->bed_id ? 'occupied' : 'assigned';

        if ($occupancy->status === 'occupied' && !$occupancy->check_in_date) {
            $occupancy->check_in_date = now()->toDateString();
        }

        $occupancy->save();

        $this->recordRoomChange(
            $occupancy,
            $oldRoomId,
            $oldBedId,
            (int) $request->room_id,
            $occupancy->bed_id,
            'assign_room'
        );

        return response()->json(['message' => 'Đã phân phòng']);
    }

    public function selectBed(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'bed_id' => 'required|integer|exists:beds,id',
        ]);

        $student = Student::where('email', $request->email)->first();
        $account = $student?->account;

        if (!$account || !$account->student_id) {
            return response()->json(['message' => 'Không tìm thấy user hoặc chưa liên kết sinh viên'], 404);
        }

        $registration = Registration::with('occupancy')->where('student_id', $account->student_id)
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn đăng ký'], 404);
        }

        $bed = Bed::find($request->bed_id);
        if ($bed && strtolower((string) $bed->status) === 'maintenance') {
            return response()->json(['message' => 'GiÆ°á»ng Ä‘ang báº£o trÃ¬ nÃªn khÃ´ng thá»ƒ chá»n'], 422);
        }

        if ($bed) {
            $isOccupiedByAnotherStudent = Occupancy::query()
                ->where('bed_id', $bed->id)
                ->where('student_id', '!=', $registration->student_id)
                ->whereIn(DB::raw('UPPER(status)'), ['ACTIVE', 'OCCUPIED', 'PENDING'])
                ->exists();

            if ($isOccupiedByAnotherStudent) {
                return response()->json(['message' => 'GiÆ°á»ng Ä‘Ã£ cÃ³ sinh viÃªn á»Ÿ'], 422);
            }
        }
        if (!$bed) {
            return response()->json(['message' => 'Giường không tồn tại'], 404);
        }

        if ($registration->occupancy?->room_id && (int) $registration->occupancy->room_id !== (int) $bed->room_id) {
            return response()->json(['message' => 'Giường không thuộc phòng đã phân.'], 422);
        }

        $occupancy = Occupancy::firstOrNew([
            'student_id' => $registration->student_id,
        ]);

        $oldRoomId = $occupancy->exists ? $occupancy->room_id : null;
        $oldBedId = $occupancy->exists ? $occupancy->bed_id : null;

        if ($occupancy->exists && $occupancy->bed_id && (int) $occupancy->bed_id !== (int) $bed->id) {
            $previousBed = Bed::find($occupancy->bed_id);
            if ($previousBed) {
                $previousBed->status = 'empty';
                $previousBed->save();
            }
        }

        $occupancy->registration_id = $registration->id;
        $occupancy->room_id = $bed->room_id;
        $occupancy->bed_id = $bed->id;
        $occupancy->status = 'pending';
        $occupancy->check_out_date = null;
        $occupancy->save();

        $this->recordRoomChange(
            $occupancy,
            $oldRoomId,
            $oldBedId,
            (int) $bed->room_id,
            (int) $bed->id,
            'select_bed'
        );

        return response()->json(['message' => 'Đã chọn giường']);
    }

    public function approveBed($id)
    {
        $registration = Registration::with(['student', 'student.account', 'occupancy'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $occupancy = $registration->occupancy;

        if (!$occupancy || !$occupancy->bed_id) {
            return response()->json(['message' => 'Sinh viên chưa chọn giường.'], 422);
        }

        $isOccupiedByAnotherStudent = Occupancy::query()
            ->where('bed_id', $occupancy->bed_id)
            ->where('student_id', '!=', $registration->student_id)
            ->whereIn(DB::raw('UPPER(status)'), ['ACTIVE', 'OCCUPIED', 'PENDING'])
            ->exists();

        if ($isOccupiedByAnotherStudent) {
            return response()->json(['message' => 'Giường đã có sinh viên ở.'], 422);
        }

        $occupancy->status = 'occupied';
        $occupancy->check_in_date = $occupancy->check_in_date ?? now()->toDateString();
        $occupancy->check_out_date = null;
        $occupancy->save();

        $bed = Bed::find($occupancy->bed_id);
        if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
            $bed->status = 'occupied';
            $bed->save();
        }

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy'])));
    }

    public function rejectBed($id)
    {
        $registration = Registration::with(['student', 'student.account', 'occupancy'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $occupancy = $registration->occupancy;

        if (!$occupancy || !$occupancy->bed_id) {
            return response()->json(['message' => 'Sinh viên chưa chọn giường.'], 422);
        }

        $bed = Bed::find($occupancy->bed_id);
        if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
            $bed->status = 'empty';
            $bed->save();
        }

        $occupancy->status = 'rejected';
        $occupancy->check_in_date = null;
        $occupancy->check_out_date = null;
        $occupancy->save();

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy'])));
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
        
        $registration = Registration::with(['student', 'student.account', 'occupancy'])->find($id);
        
        Log::info("RegistrationController.show($id) - found registration", [
            'id' => $registration?->id,
            'status' => $registration?->status,
            'student_id' => $registration?->student_id,
        ]);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy'], 404);
        }

        return response()->json($this->formatRegistration($registration));
    }

    public function getRegistrationHistory($email, $semester)
    {
        Log::info("RegistrationController.getRegistrationHistory - email: $email, semester: $semester");
        
        $student = Student::where('email', $email)->first();
        $account = $student?->account;

        if (!$account || !$account->student_id) {
            Log::info("RegistrationController.getRegistrationHistory - student not found");
            return response()->json([]);
        }

        $registrations = Registration::with(['student', 'student.account', 'occupancy'])
            ->where('student_id', $account->student_id)
            ->where('semester', $semester)
            ->orderBy('id', 'asc')
            ->get();

        Log::info("RegistrationController.getRegistrationHistory - found registrations", [
            'count' => $registrations->count(),
            'student_id' => $account->student_id,
        ]);

        return $registrations->map(function ($registration) {
            return $this->formatRegistration($registration);
        });
    }
}
