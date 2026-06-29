<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use Illuminate\Http\Request;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\Occupancy;
use App\Models\CheckoutRequest;
use App\Models\Account;
use App\Models\Student;
use App\Models\Room;
use App\Models\Bed;
use App\Models\Floor;
use App\Models\StudentPriority;
use App\Models\Blacklist;
use App\Models\ElectricityBill;
use App\Models\RoomFeeBill;
use App\Models\Notification;
use App\Helpers\StorageHelper;
use App\Services\AutoReviewService;
use App\Services\RoomFeeBillingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        $registration->loadMissing('period');
        $student = $registration->student;
        $blacklist = $student
            ? Blacklist::where('student_id', $student->id)->latest('id')->first()
            : null;
        $formData = [
            'mssv' => $student?->student_code,
            'fullName' => $student?->full_name,
            'birthDate' => $student?->date_of_birth,
            'gender' => $student?->gender,
            'class' => $student?->class_name,
            'department' => $student?->faculty,
            'nationality' => $student?->nationality,
            'ethnicity' => $student?->ethnicity,
            'religion' => $student?->religion,
            'phone' => $student?->phone,
            'cccd' => $student?->cccd,
            'cccdIssueDate' => $student?->cccd_issued_date,
            'cccdIssuePlace' => $student?->cccd_issued_place,
            'address' => $student?->permanent_address,
            'father_name' => $registration->father_name,
            'father_phone' => $registration->father_phone,
            'father_job' => $registration->father_job,
            'mother_name' => $registration->mother_name,
            'mother_phone' => $registration->mother_phone,
            'mother_job' => $registration->mother_job,
            'familyContactAddress' => $registration->parent_address,
        ];

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
            'reason' => $registration->rejection_reason,
            'rejection_reason' => $registration->rejection_reason,
            'auto_decision' => $registration->auto_decision,
            'auto_decision_reason' => $registration->auto_decision_reason,
            'registration_period_id' => $registration->registration_period_id,
            'channel' => $registration->period?->channel,
            'period_name' => $registration->period?->name,
            'period_status' => $registration->period?->status,
            'approved_at' => $registration->approved_at?->toDateString(),
            'occupancy_id' => $registration->occupancy?->id,
            'assigned_room_id' => $registration->occupancy?->room_id,
            'assigned_bed_id' => $registration->occupancy?->bed_id,
            'bed_approval_status' => $registration->occupancy?->bed_approval_status,
            'occupancy_status' => $this->mapOccupancyStatus($registration->occupancy),
            'checkout_requested' => (bool) ($registration->occupancy?->pendingCheckoutRequest),
            'occupancy_reason' => $registration->occupancy?->reason,
            'check_in_date' => $registration->occupancy?->check_in_date,
            'check_out_date' => $registration->occupancy?->check_out_date,
            'room_assigned_at' => $registration->occupancy?->created_at,
            'blacklist' => $blacklist ? [
                'reason' => $blacklist->reason,
                'source' => $blacklist->source,
                'created_at' => $blacklist->created_at,
            ] : null,
            'note' => $registration->note,
            'created_at' => $registration->created_at,
            'student' => $registration->student,
            'priority_criteria' => StudentPriority::with(['criteria', 'evidences'])
                    ->where('registration_id', $registration->id)
                    ->get()
                    ->map(function ($p) {
                        $urls = $p->evidences
                            ->map(fn($e) => $this->getImageUrl($e->file_url))
                            ->values()
                            ->all();
                        // Tương thích ngược: đơn cũ lưu 1 ảnh ở evidence_url
                        if (empty($urls) && $p->evidence_url) {
                            $urls = [$this->getImageUrl($p->evidence_url)];
                        }
                        return [
                            'id' => $p->id,
                            'criteria_id' => $p->priority_criteria_id,
                            'code' => $p->criteria?->code,
                            'name' => $p->criteria?->name,
                            'evidence_urls' => $urls,
                            'status' => $p->status,
                        ];
                    })
                    ->all(),
        ];
    }

    private function recordRoomChange(?Occupancy $occupancy, ?int $oldRoomId, ?int $oldBedId, ?int $newRoomId, ?int $newBedId, ?string $reason = null): void
    {
        if (!$occupancy || ($oldRoomId === $newRoomId && $oldBedId === $newBedId)) {
            return;
        }

        $changeType = $reason === 'assign_room' ? 'ADMIN_TRANSFER' : 'PERMANENT';

        DB::table('room_change_log')->insert([
            'occupancy_id' => $occupancy->id,
            'old_room_id' => $oldRoomId,
            'old_bed_id' => $oldBedId,
            'new_room_id' => $newRoomId,
            'new_bed_id' => $newBedId,
            'transfer_reason' => $reason,
            'change_type' => $changeType,
            'status' => null,
            'transferred_at' => now(),
        ]);
    }

    private function notifyRoomAssignmentChange(
        Registration $registration,
        ?int $oldRoomId,
        ?int $oldBedId,
        ?int $newRoomId,
        ?int $newBedId,
    ): void {
        $student = $registration->student;

        if (! $student || ! $newRoomId || $oldRoomId === $newRoomId) {
            return;
        }

        $oldRoom = $oldRoomId ? Room::with('floor')->find($oldRoomId) : null;
        $newRoom = Room::with('floor')->find($newRoomId);
        $oldBed = $oldBedId ? Bed::find($oldBedId) : null;
        $newBed = $newBedId ? Bed::find($newBedId) : null;

        $oldRoomCode = $oldRoom ? (($oldRoom->floor?->building_code ?? '') . ($oldRoom->room_number ?? '')) : null;
        $newRoomCode = $newRoom ? (($newRoom->floor?->building_code ?? '') . ($newRoom->room_number ?? '')) : null;

        if (! $newRoomCode) {
            return;
        }

        $isRoomChange = ! empty($oldRoomCode);
        $title = $isRoomChange ? 'Thông báo đổi phòng lưu trú' : 'Thông báo phân phòng lưu trú';
        $type = $isRoomChange ? 'room_assignment_changed' : 'room_assigned';
        $oldLabel = $oldRoomCode
            ? "Phòng {$oldRoomCode}" . ($oldBed ? ", Giường {$oldBed->bed_number}" : '')
            : null;
        $newLabel = "Phòng {$newRoomCode}" . ($newBed ? ", Giường {$newBed->bed_number}" : '');

        $content = $isRoomChange
            ? "Ban quản lý đã đổi phòng lưu trú của bạn từ {$oldLabel} sang {$newLabel}."
            : "Ban quản lý đã phân phòng lưu trú cho bạn: {$newLabel}.";

        if (! $newBed) {
            $content .= ' Vui lòng đăng nhập hệ thống để chọn giường trong phòng mới.';
        }

        $this->createStudentNotification($student->id, $title, $content, $type);
        $this->sendStudentNotificationEmail($student, $title, $content);
    }

    private function createStudentNotification(int $studentId, string $title, string $content, string $type): void
    {
        try {
            $notification = Notification::create([
                'student_id'  => $studentId,
                'title'       => $title,
                'content'     => $content,
                'type'        => $type,
                'target_type' => 'individual',
                'send_email'  => true,
            ]);
            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $studentId,
                'is_read'         => false,
                'read_at'         => null,
            ]);
        } catch (\Exception) {}
    }

    private function sendStudentNotificationEmail(Student $student, string $title, string $content): void
    {
        if (empty($student->email)) {
            return;
        }

        try {
            $name = htmlspecialchars($student->full_name ?? '');
            $safeTitle = htmlspecialchars($title);
            $safeContent = nl2br(htmlspecialchars($content));
            $body = "
                <div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152;'>
                    <h2 style='color:#244cb8;margin-top:0;'>{$safeTitle}</h2>
                    <p>Xin chào <strong>{$name}</strong>,</p>
                    <p style='line-height:1.7;'>{$safeContent}</p>
                    <p style='color:#6b7280;font-size:12px;margin-top:32px;'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
                </div>
            ";

            Mail::send([], [], function ($message) use ($student, $title, $body) {
                $message->to($student->email, $student->full_name ?? '')
                    ->subject("KTX — {$title}")
                    ->html($body);
            });
        } catch (\Exception) {}
    }
    
    public function eligibility(Request $request): \Illuminate\Http\JsonResponse
    {
        $email = $request->query('email');

        if (!$email) {
            return response()->json(['message' => 'Email là bắt buộc'], 422);
        }

        $student = Student::where('email', $email)->first();

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên'], 404);
        }

        // Check 1: Đã có đơn đang chờ xét duyệt TRONG ĐỢT ĐANG MỞ
        // Chỉ chặn khi status='submitted' trong period đang active.
        // Đơn từ đợt cũ đã đóng (submitted/rejected) không chặn sinh viên đăng ký đợt mới.
        $activePeriodId = RegistrationPeriod::where('status', 'active')->value('id');

        $hasSubmittedInActivePeriod = $activePeriodId !== null
            && Registration::where('student_id', $student->id)
                ->where('registration_period_id', $activePeriodId)
                ->where('status', 'submitted')
                ->exists();

        if ($hasSubmittedInActivePeriod) {
            return response()->json([
                'eligible'       => false,
                'reason_code'    => 'has_active_application',
                'reason_message' => 'Đơn của bạn đã được ghi nhận, kết quả sẽ thông báo sau',
            ]);
        }

        // Check 1b: Đã có đơn được duyệt — không cho gửi đơn mới
        $hasApproved = Registration::where('student_id', $student->id)
            ->where('status', 'approved')
            ->exists();

        if ($hasApproved) {
            return response()->json([
                'eligible'       => false,
                'reason_code'    => 'already_approved',
                'reason_message' => 'Đơn đăng ký của bạn đã được duyệt',
                'redirect'       => '/student/room-status',
            ]);
        }

        // Check 2: Đang lưu trú (occupancy ACTIVE)
        $activeOccupancy = Occupancy::where('student_id', $student->id)
            ->where('status', 'ACTIVE')
            ->with('room')
            ->first();

        if ($activeOccupancy) {
            $room     = $activeOccupancy->room;
            $roomName = $room ? ($room->building_code . $room->room_number) : 'phòng hiện tại';

            return response()->json([
                'eligible'       => false,
                'reason_code'    => 'already_residing',
                'reason_message' => "Bạn đang ở phòng {$roomName}, muốn ở tiếp dùng chức năng Gia hạn",
                'redirect'       => '/student/renewal',
            ]);
        }

        // Check 3: Tình trạng học tập không hợp lệ
        $allowedAcademicStatuses = ['studying', 'overtime_training'];

        if (!in_array($student->academic_status, $allowedAcademicStatuses, true)) {
            $message = match ($student->academic_status) {
                'graduated'       => 'Bạn đã tốt nghiệp, không thuộc diện được ở KTX.',
                'transferred'     => 'Bạn đã chuyển trường.',
                'suspended'       => 'Tài khoản đang bị đình chỉ học tập.',
                'temporary_leave' => 'Bạn đang trong thời gian bảo lưu học tập.',
                'dropped_out'     => 'Bạn đã thôi học.',
                default           => 'Bạn không trong tình trạng đang học.',
            };

            return response()->json([
                'eligible'        => false,
                'reason_code'     => 'not_studying',
                'academic_status' => $student->academic_status,
                'reason_message'  => $message,
            ]);
        }

        // Check 4: Thuộc blacklist
        if (Blacklist::isBanned($student->id)) {
            return response()->json([
                'eligible'       => false,
                'reason_code'    => 'blacklisted',
                'reason_message' => 'Bạn thuộc danh sách không được đăng ký ở KTX',
            ]);
        }

        // Check 5: Quá năm học tối đa
        $maxStudyYear = config('ktx.max_study_year', 6);

        if ($student->current_year !== null && $student->current_year > $maxStudyYear) {
            return response()->json([
                'eligible'       => false,
                'reason_code'    => 'not_eligible_year',
                'reason_message' => 'Bạn đã quá thời hạn đào tạo tối đa, không thuộc diện ở KTX',
            ]);
        }

        // Check 6: Còn nợ hóa đơn
        $unpaidStatuses = ['unpaid', 'overdue'];

        $hasUnpaidBills = ElectricityBill::where('student_id', $student->id)
                ->whereIn('status', $unpaidStatuses)
                ->exists()
            || RoomFeeBill::where('student_id', $student->id)
                ->whereIn('status', $unpaidStatuses)
                ->exists();

        if ($hasUnpaidBills) {
            return response()->json([
                'eligible'       => false,
                'reason_code'    => 'unpaid_bills',
                'reason_message' => 'Bạn còn hóa đơn chưa thanh toán',
                'redirect'       => '/student/payment',
            ]);
        }

        // Check 7: Không có đợt đăng ký nào đang mở (kiểm tra sau khi đã xác nhận sv đủ điều kiện)
        // Ưu tiên 1: đợt active (đang mở nhận đơn)
        $activePeriod = RegistrationPeriod::where('status', 'active')
            ->orderBy('start_date', 'asc')
            ->first();

        // Ưu tiên 2: đợt pending gần nhất (sắp mở)
        if (!$activePeriod) {
            $activePeriod = RegistrationPeriod::where('status', 'pending')
                ->orderBy('start_date', 'asc')
                ->first();
        }

        // Không có active lẫn pending
        if (!$activePeriod) {
            $processingMain = RegistrationPeriod::where('channel', 'main')
                ->where('status', 'processing')
                ->exists();

            $message = $processingMain
                ? 'Đang xử lý đợt đăng ký, vui lòng quay lại sau'
                : 'Hiện chưa có đợt đăng ký nào đang mở, vui lòng theo dõi thông báo từ Ban quản lý';

            return response()->json([
                'eligible'       => false,
                'reason_code'    => 'no_open_channel',
                'reason_message' => $message,
            ]);
        }

        // Check 8: Đủ điều kiện (active hoặc pending)
        return response()->json([
            'eligible'     => true,
            'channel_info' => [
                'channel'         => $activePeriod->channel,
                'period_name'     => $activePeriod->name,
                'school_year'     => $activePeriod->school_year,
                'semester'        => $activePeriod->semester,
                'start_date'      => $activePeriod->start_date?->toDateString(),
                'end_date'        => $activePeriod->end_date?->toDateString(),
                'stay_start_date' => $activePeriod->stay_start_date?->toDateString(),
                'stay_end_date'   => $activePeriod->stay_end_date?->toDateString(),
                'status'          => $activePeriod->status,
            ],
        ]);
    }

    public function index()
    {
        $registrations = Registration::with(['student', 'student.account', 'occupancy', 'occupancy.pendingCheckoutRequest', 'period'])->get();

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

        // Re-check điều kiện sinh viên để tránh race condition sau khi eligibility GET đã trả eligible=true
        $allowedAcademicStatuses = ['studying', 'overtime_training'];
        if (!in_array($student->academic_status, $allowedAcademicStatuses, true)) {
            return response()->json(['message' => 'Bạn không đủ điều kiện đăng ký nội trú', 'reason_code' => 'not_studying'], 422);
        }
        if (Blacklist::isBanned($student->id)) {
            return response()->json(['message' => 'Bạn thuộc danh sách không được đăng ký ở KTX', 'reason_code' => 'blacklisted'], 422);
        }
        $maxStudyYear = config('ktx.max_study_year', 6);
        if ($student->current_year !== null && $student->current_year > $maxStudyYear) {
            return response()->json(['message' => 'Bạn đã quá thời hạn đào tạo tối đa', 'reason_code' => 'not_eligible_year'], 422);
        }
        $unpaidStatuses = ['unpaid', 'overdue'];
        if (ElectricityBill::where('student_id', $student->id)->whereIn('status', $unpaidStatuses)->exists()
            || RoomFeeBill::where('student_id', $student->id)->whereIn('status', $unpaidStatuses)->exists()) {
            return response()->json(['message' => 'Bạn còn hóa đơn chưa thanh toán', 'reason_code' => 'unpaid_bills'], 422);
        }
        // Chặn nếu đã có đơn được duyệt (1 sinh viên chỉ có 1 đơn approved tại một thời điểm)
        $hasApproved = Registration::where('student_id', $student->id)
            ->where('status', 'approved')
            ->exists();
        if ($hasApproved) {
            return response()->json(['message' => 'Bạn đã có đơn được duyệt. Không thể gửi đơn mới.', 'reason_code' => 'already_approved'], 422);
        }

        $activePeriod = RegistrationPeriod::where('status', 'active')->first();
        if (!$activePeriod) {
            return response()->json(['message' => 'Hiện không có đợt đăng ký nào đang mở', 'reason_code' => 'no_open_channel'], 422);
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

        $rawCriteriaIds = $request->input('priority_criteria_ids');
        $criteriaIds = [];
        if ($rawCriteriaIds) {
            $parsed = is_array($rawCriteriaIds) ? $rawCriteriaIds : json_decode($rawCriteriaIds, true);
            $criteriaIds = is_array($parsed) ? array_map('intval', $parsed) : [];
        }

        // Đã chọn diện ưu tiên thì bắt buộc phải có ít nhất 1 ảnh minh chứng
        foreach ($criteriaIds as $criteriaId) {
            if (!$request->hasFile('priority_evidence_' . $criteriaId)) {
                return response()->json([
                    'message' => 'Vui lòng tải lên minh chứng cho các diện ưu tiên đã chọn.',
                ], 422);
            }
        }

        try {
            $result = DB::transaction(function () use ($account, $currentStudent, $data, $criteriaIds, $activePeriod) {
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

                // Unique constraint: mỗi sinh viên chỉ có 1 đơn submitted trong 1 đợt
                $hasPendingInPeriod = Registration::where('student_id', $student->id)
                    ->where('registration_period_id', $activePeriod->id)
                    ->where('status', 'submitted')
                    ->lockForUpdate()
                    ->exists();

                if ($hasPendingInPeriod) {
                    throw new RuntimeException('DUPLICATE_PENDING_REGISTRATION');
                }

                $registrationPayload = [
                    'student_id'             => $student->id,
                    'registration_period_id' => $activePeriod->id,
                    'registration_type'      => 'new',
                    'avatar_url'             => $data['avatar'] ?? $existingAvatar,
                    'cccd_front_url'         => $data['cccd_front_url'] ?? null,
                    'cccd_back_url'          => $data['cccd_back_url'] ?? null,
                    'semester'               => $activePeriod->semester,
                    'school_year'            => $activePeriod->school_year,
                    'status'                 => 'submitted',
                    'father_name'            => $data['father_name'] ?? ($data['parent_name'] ?? ''),
                    'father_birth_year'      => $data['father_birth_year'] ?? '',
                    'father_job'             => $data['father_job'] ?? '',
                    'father_phone'           => $data['father_phone'] ?? ($data['parent_phone'] ?? ''),
                    'mother_name'            => $data['mother_name'] ?? '',
                    'mother_birth_year'      => $data['mother_birth_year'] ?? '',
                    'mother_job'             => $data['mother_job'] ?? '',
                    'mother_phone'           => $data['mother_phone'] ?? '',
                    'parent_address'         => $data['parent_address'] ?? ($data['permanent_address'] ?? ''),
                    'stay_from_date'         => $activePeriod->stay_start_date?->toDateString(),
                    'stay_to_date'           => $activePeriod->stay_end_date?->toDateString(),
                    'commitment_confirm'     => $data['commitment_confirm'] ?? false,
                ];

                $registration = Registration::create($registrationPayload);

                // Lưu tiêu chí ưu tiên nếu sinh viên chọn — gắn với đơn này
                if (!empty($criteriaIds)) {
                    foreach ($criteriaIds as $criteriaId) {
                        StudentPriority::create([
                            'student_id' => $student->id,
                            'registration_id' => $registration->id,
                            'priority_criteria_id' => $criteriaId,
                            'status' => 'pending',
                        ]);
                    }
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

        // Xử lý file minh chứng sau transaction (tối đa 6 ảnh / tiêu chí)
        if (!empty($criteriaIds)) {
            $registrationId = $result['registration']->id;
            foreach ($criteriaIds as $criteriaId) {
                $evidenceKey = 'priority_evidence_' . $criteriaId;
                if (!$request->hasFile($evidenceKey)) {
                    continue;
                }

                $files = $request->file($evidenceKey);
                if (!is_array($files)) {
                    $files = [$files];
                }
                $files = array_slice($files, 0, 6);

                $priority = StudentPriority::where('registration_id', $registrationId)
                    ->where('priority_criteria_id', $criteriaId)
                    ->first();
                if (!$priority) {
                    continue;
                }

                $path = 'students/priority_evidence';
                foreach ($files as $index => $file) {
                    $filename = 'evidence_' . $criteriaId . '_' . time() . '_' . uniqid() . '_' . $index . '.' . $file->getClientOriginalExtension();

                    if (StorageHelper::isRailwayWithVolume()) {
                        $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
                        $fullDir = $volumePath . '/' . $path;
                        if (!file_exists($fullDir)) {
                            mkdir($fullDir, 0755, true);
                        }
                        $file->move($fullDir, $filename);
                        $fileUrl = $path . '/' . $filename;
                    } else {
                        $fileUrl = $file->store($path, 'public');
                    }

                    $priority->evidences()->create(['file_url' => $fileUrl]);
                }
            }
        }

        $autoReview = new AutoReviewService();
        $autoReview->handleAfterSubmission($result['registration']);

        return response()->json([
            'message' => 'Đăng ký thành công',
            'data' => $result
        ], 201);
    }

    public function getMyRegistration(Request $request)
    {
        $email = $request->query('email');
        $semester = $request->query('semester');

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

        // Chỉ lọc theo semester khi FE truyền vào; mặc định lấy đơn mới nhất
        // của sinh viên ở bất kỳ học kỳ nào để trạng thái (submitted/approved/
        // rejected) luôn hiển thị đúng.
        $registration = Registration::with(['student', 'student.account', 'occupancy', 'period'])
            ->where('student_id', $account->student_id)
            ->when($semester, fn ($query) => $query->where('semester', $semester))
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
        $registration->approved_at = now();
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
            $maintenanceBeds = $room->beds
                ->filter(fn (Bed $bed) => strtolower((string) $bed->status) === 'maintenance')
                ->count();
            $occupiedBeds = Occupancy::occupiedBedsQuery()
                ->where('room_id', $room->id)
                ->count();
            $availableBeds = max($totalBeds - $maintenanceBeds - $occupiedBeds, 0);

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

        $registration = Registration::with(['occupancy', 'period', 'student'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $occupancy = Occupancy::firstOrNew([
            'student_id'      => $registration->student_id,
            'registration_id' => $registration->id,
        ]);

        $oldRoomId = $occupancy->exists ? $occupancy->room_id : null;
        $oldBedId = $occupancy->exists ? $occupancy->bed_id : null;

        if ($occupancy->exists && $occupancy->bed_id) {
            $currentBed = Bed::find($occupancy->bed_id);
            if (!$currentBed || (int) $currentBed->room_id !== (int) $request->room_id) {
                if ($currentBed) {
                    $currentBed->status = 'active';
                    $currentBed->save();
                }

                $occupancy->bed_id = null;
            }
        }

        $period = $registration->period;

        $occupancy->registration_id = $registration->id;
        $occupancy->room_id = $request->room_id;
        $occupancy->bed_id = null;
        $occupancy->check_in_date = $period?->stay_start_date?->toDateString();
        $occupancy->check_out_date = $period?->stay_end_date?->toDateString();
        // Admin confirmed the room; student must still pick a bed.
        $occupancy->status = 'ROOM_CONFIRMED';
        $occupancy->bed_approval_status = null;

        $occupancy->save();

        $this->recordRoomChange(
            $occupancy,
            $oldRoomId,
            $oldBedId,
            (int) $request->room_id,
            $occupancy->bed_id,
            'assign_room'
        );

        $this->notifyRoomAssignmentChange(
            $registration,
            $oldRoomId,
            $oldBedId,
            (int) $request->room_id,
            $occupancy->bed_id,
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

        // 1. Tìm giường — phải tồn tại trước khi check bất cứ điều gì
        $bed = Bed::find($request->bed_id);
        if (!$bed) {
            return response()->json(['message' => 'Giường không tồn tại.'], 404);
        }

        // 2. Giường phải thuộc đúng phòng được phân — luôn validate, không bypass dù occupancy null
        $assignedRoomId = $registration->occupancy?->room_id;
        if (!$assignedRoomId || (int) $assignedRoomId !== (int) $bed->room_id) {
            return response()->json(['message' => 'Giường không thuộc phòng của bạn.'], 403);
        }

        // 3. Giường không được đang bảo trì
        if (strtolower((string) $bed->status) === 'maintenance') {
            return response()->json(['message' => 'Giường đang bảo trì, vui lòng chọn giường khác.'], 422);
        }

        // 4. Giường chưa được sinh viên khác giữ chỗ hoặc đang ở.
        $isOccupiedByAnotherStudent = Occupancy::query()
            ->occupiedBeds()
            ->where('bed_id', $bed->id)
            ->where('student_id', '!=', $registration->student_id)
            ->exists();

        if ($isOccupiedByAnotherStudent) {
            return response()->json(['message' => 'Giường vừa được chọn bởi người khác, vui lòng chọn giường khác.'], 422);
        }

        $occupancy = Occupancy::firstOrNew([
            'student_id'      => $registration->student_id,
            'registration_id' => $registration->id,
        ]);

        $oldRoomId = $occupancy->exists ? $occupancy->room_id : null;
        $oldBedId = $occupancy->exists ? $occupancy->bed_id : null;

        if ($occupancy->exists && $occupancy->bed_id && (int) $occupancy->bed_id !== (int) $bed->id) {
            $previousBed = Bed::find($occupancy->bed_id);
            if ($previousBed) {
                $previousBed->status = 'active';
                $previousBed->save();
            }
        }

        $period = $registration->period;

        $occupancy->registration_id    = $registration->id;
        $occupancy->room_id            = $bed->room_id;
        $occupancy->bed_id             = $bed->id;
        $occupancy->status             = 'PENDING_PAYMENT';
        $occupancy->bed_approval_status = 'approved';
        $occupancy->check_in_date  = $occupancy->check_in_date  ?? $period?->stay_start_date?->toDateString();
        $occupancy->check_out_date = $occupancy->check_out_date ?? $period?->stay_end_date?->toDateString();
        $occupancy->save();

        $this->recordRoomChange(
            $occupancy,
            $oldRoomId,
            $oldBedId,
            (int) $bed->room_id,
            (int) $bed->id,
            'select_bed'
        );

        // Sinh hóa đơn tháng đầu để sinh viên thanh toán trước khi ACTIVE
        $billingService = app(RoomFeeBillingService::class);
        $bill = $billingService->createInitialBill($occupancy);

        return response()->json([
            'message' => 'Đã chọn giường. Vui lòng thanh toán hóa đơn để hoàn tất lưu trú.',
            'bill_id' => $bill?->id,
        ]);
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
            ->occupiedBeds()
            ->where('bed_id', $occupancy->bed_id)
            ->where('student_id', '!=', $registration->student_id)
            ->exists();

        if ($isOccupiedByAnotherStudent) {
            return response()->json(['message' => 'Giường đã có sinh viên ở.'], 422);
        }

        $hasPaidInitialBill = RoomFeeBill::query()
            ->where('occupancy_id', $occupancy->id)
            ->where('status', 'paid')
            ->exists();

        if (! $hasPaidInitialBill) {
            return response()->json([
                'message' => 'Sinh viên chưa thanh toán hóa đơn đầu. Chưa thể kích hoạt lưu trú.',
            ], 422);
        }

        $period = $registration->period;

        $occupancy->status = 'ACTIVE';
        $occupancy->bed_approval_status = 'approved';
        $occupancy->check_in_date  = $occupancy->check_in_date  ?? $period?->stay_start_date?->toDateString() ?? now()->toDateString();
        $occupancy->check_out_date = $occupancy->check_out_date ?? $period?->stay_end_date?->toDateString();
        $occupancy->save();

        $bed = Bed::find($occupancy->bed_id);
        if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
            $bed->status = 'active';
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
            $bed->status = 'active';
            $bed->save();
        }

        // Bed rejected; student returns to the bed-selection step.
        $occupancy->status = 'ROOM_CONFIRMED';
        $occupancy->bed_approval_status = 'rejected';
        $occupancy->check_in_date = null;
        $occupancy->check_out_date = null;
        $occupancy->save();

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy'])));
    }

    public function requestCheckout(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'reason' => 'required|string|max:1000',
            'expected_leave_date' => 'nullable|date',
        ]);

        $student = Student::where('email', $request->email)->first();
        $account = $student?->account;

        if (!$student || !$account?->student_id) {
            return response()->json(['message' => 'Không tìm thấy user hoặc chưa liên kết sinh viên'], 404);
        }

        $registration = Registration::with(['student', 'student.account', 'occupancy'])
            ->where('student_id', $account->student_id)
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        if (!$registration || !$registration->occupancy || !$registration->occupancy->bed_id) {
            return response()->json(['message' => 'Sinh viên chưa có thông tin lưu trú.'], 404);
        }

        $occupancy = $registration->occupancy;

        // Mỗi occupancy chỉ có 1 yêu cầu thôi ở đang chờ duyệt.
        $hasPending = CheckoutRequest::where('occupancy_id', $occupancy->id)
            ->where('status', 'pending')
            ->exists();
        if ($hasPending) {
            return response()->json(['message' => 'Đã có yêu cầu thôi ở đang chờ duyệt.'], 422);
        }

        // Student requested checkout; stays ACTIVE until admin confirms.
        CheckoutRequest::create([
            'occupancy_id' => $occupancy->id,
            'student_id' => $occupancy->student_id ?? $account->student_id,
            'reason' => $request->reason,
            'expected_leave_date' => $request->expected_leave_date ?? now()->toDateString(),
            'status' => 'pending',
        ]);

        // Giữ hiển thị hiện có: lưu lý do/ngày dự kiến lên occupancy (vẫn ACTIVE).
        $occupancy->reason = $request->reason;
        $occupancy->check_out_date = $request->expected_leave_date;
        $occupancy->save();

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy', 'occupancy.pendingCheckoutRequest'])));
    }

    public function confirmCheckout($id)
    {
        $registration = Registration::with(['student', 'student.account', 'occupancy'])->find($id);

        if (!$registration || !$registration->occupancy) {
            return response()->json(['message' => 'Không tìm thấy thông tin lưu trú.'], 404);
        }

        $occupancy = $registration->occupancy;

        DB::transaction(function () use ($occupancy) {
            $now          = Carbon::now();
            $currentMonth = $now->month;
            $currentYear  = $now->year;

            // Hủy tất cả hóa đơn CHƯA thanh toán của các quý SAU tháng hiện tại.
            // Hóa đơn quý hiện tại (bill.month <= currentMonth trong cùng năm) được giữ lại —
            // sinh viên không hoàn tiền dù rời đi trước khi hết quý.
            RoomFeeBill::where('occupancy_id', $occupancy->id)
                ->where('status', 'unpaid')
                ->where(function ($q) use ($currentMonth, $currentYear) {
                    $q->where('year', '>', $currentYear)
                      ->orWhere(function ($q2) use ($currentMonth, $currentYear) {
                          $q2->where('year', $currentYear)->where('month', '>', $currentMonth);
                      });
                })
                ->delete();

            $occupancy->status         = 'COMPLETED';
            $occupancy->check_out_date = $occupancy->check_out_date ?? now()->toDateString();
            $occupancy->save();

            // Chốt yêu cầu thôi ở đang chờ (nếu có).
            CheckoutRequest::where('occupancy_id', $occupancy->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'processed_at' => now()]);

            $bed = Bed::find($occupancy->bed_id);
            if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
                $bed->status = 'active';
                $bed->save();
            }
        });

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy'])));
    }

    public function forceCheckout(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $registration = Registration::with(['student', 'student.account', 'occupancy'])->find($id);

        if (!$registration || !$registration->occupancy) {
            return response()->json(['message' => 'Không tìm thấy thông tin lưu trú.'], 404);
        }

        $occupancy = $registration->occupancy;

        DB::transaction(function () use ($occupancy, $request) {
            $now          = Carbon::now();
            $currentMonth = $now->month;
            $currentYear  = $now->year;

            // Hủy hóa đơn chưa trả của các quý sau (áp dụng chính sách không hoàn tiền)
            RoomFeeBill::where('occupancy_id', $occupancy->id)
                ->where('status', 'unpaid')
                ->where(function ($q) use ($currentMonth, $currentYear) {
                    $q->where('year', '>', $currentYear)
                      ->orWhere(function ($q2) use ($currentMonth, $currentYear) {
                          $q2->where('year', $currentYear)->where('month', '>', $currentMonth);
                      });
                })
                ->delete();

            $occupancy->status         = 'TERMINATED';
            $occupancy->reason         = $request->reason;
            $occupancy->check_out_date = now()->toDateString();
            $occupancy->save();

            CheckoutRequest::where('occupancy_id', $occupancy->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'processed_at' => now()]);

            $bed = Bed::find($occupancy->bed_id);
            if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
                $bed->status = 'active';
                $bed->save();
            }
        });

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy'])));
    }

    public function reject($id, Request $request)
    {
        $request->validate([
            'rejectionReason' => 'required|string|max:500',
        ]);

        $registration = Registration::with(['student', 'student.account', 'occupancy'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $registration->status = 'rejected';
        $registration->rejection_reason = $request->input('rejectionReason');
        $registration->save();

        return response()->json($this->formatRegistration($registration));
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

        $registrations = Registration::with(['student', 'student.account', 'occupancy', 'occupancy.pendingCheckoutRequest'])
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

    public function patchAutoDecision($id, Request $request)
    {
        $request->validate([
            'decision' => 'required|in:approve,reject,review',
            'reason'   => 'nullable|string|max:1000',
        ]);

        $registration = Registration::with(['student', 'student.account', 'occupancy', 'period'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        $registration->auto_decision = $request->input('decision');
        $registration->auto_decision_reason = $request->input('reason');
        $registration->save();

        return response()->json($this->formatRegistration($registration));
    }

    public function confirmSingle($id)
    {
        $registration = Registration::with(['student', 'student.account', 'occupancy', 'period'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        if (!in_array($registration->auto_decision, ['approve', 'reject'])) {
            return response()->json(['message' => 'Đơn chưa có quyết định tự động (auto_decision phải là approve hoặc reject)'], 422);
        }

        $registration->status = $registration->auto_decision === 'approve' ? 'approved' : 'rejected';
        if ($registration->status === 'approved') {
            $registration->approved_at = now();
        }
        if ($registration->status === 'rejected' && $registration->auto_decision_reason) {
            $registration->rejection_reason = $registration->auto_decision_reason;
        }
        $registration->save();

        return response()->json($this->formatRegistration($registration));
    }

    public function confirmBatch($periodId)
    {
        $period = RegistrationPeriod::find($periodId);

        if (!$period) {
            return response()->json(['message' => 'Không tìm thấy đợt đăng ký'], 404);
        }

        if ($period->channel !== 'main') {
            return response()->json(['message' => 'Chỉ áp dụng cho đợt kênh chính (main)'], 422);
        }

        if ($period->status !== 'processing') {
            return response()->json(['message' => 'Đợt phải đang ở trạng thái processing'], 422);
        }

        $registrations = Registration::where('registration_period_id', $periodId)
            ->whereIn('status', ['submitted'])
            ->get();

        $confirmed = 0;
        $skippedReview = 0;
        $skippedNull = 0;

        foreach ($registrations as $reg) {
            if ($reg->auto_decision === 'approve') {
                $reg->status = 'approved';
                $reg->approved_at = now();
                $reg->save();
                $confirmed++;
            } elseif ($reg->auto_decision === 'reject') {
                $reg->status = 'rejected';
                if ($reg->auto_decision_reason) {
                    $reg->rejection_reason = $reg->auto_decision_reason;
                }
                $reg->save();
                $confirmed++;
            } elseif ($reg->auto_decision === 'review') {
                $skippedReview++;
            } else {
                $skippedNull++;
            }
        }

        $period->status = 'closed';
        $period->save();

        return response()->json([
            'confirmed'      => $confirmed,
            'skipped_review' => $skippedReview,
            'skipped_null'   => $skippedNull,
        ]);
    }

    private function mapOccupancyStatus(?Occupancy $occupancy): ?string
    {
        if (! $occupancy) {
            return null;
        }

        if ($occupancy->pendingCheckoutRequest) {
            return 'checkout_requested';
        }

        return match ($occupancy->status) {
            'COMPLETED' => $occupancy->reason === 'FORCE_EVICTED' ? 'forced_checkout' : 'checked_out',
            'TERMINATED' => 'forced_checkout',
            default     => $occupancy->status,
        };
    }
}
