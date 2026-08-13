<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreRegistrationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\CheckoutRequest;
use App\Models\OccupancyExtension;
use App\Models\AdminNotification;
use App\Models\Account;
use App\Models\Student;
use App\Models\Room;
use App\Models\Bed;
use App\Models\Floor;
use App\Models\StudentPriority;
use App\Models\Blacklist;
use App\Models\ElectricityBill;
use App\Models\RoomFeeBill;
use App\Helpers\StorageHelper;
use App\Jobs\ProcessPriorityEvidenceJob;
use App\Services\AutoReviewService;
use App\Services\DormCapacityService;
use App\Services\DormReservationExpiryService;
use App\Services\PriorityRankingService;
use App\Services\RoomFeeBillingService;
use App\Services\StudentNotificationService;
use Carbon\Carbon;
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

        // Some records (e.g. registrations converted from a tân sinh viên
        // dorm reservation) already have a storage prefix baked into the
        // stored path — strip it so it isn't duplicated below.
        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));
        
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
            'father_birth_year' => $registration->father_birth_year,
            'father_phone' => $registration->father_phone,
            'father_job' => $registration->father_job,
            'mother_name' => $registration->mother_name,
            'mother_birth_year' => $registration->mother_birth_year,
            'mother_phone' => $registration->mother_phone,
            'mother_job' => $registration->mother_job,
            'familyContactAddress' => $registration->parent_address,
            'emergency_contact_name' => $registration->emergency_contact_name,
            'emergency_contact_phone' => $registration->emergency_contact_phone,
            'emergency_contact_relationship' => $registration->emergency_contact_relationship,
        ];

        return [
            'id' => $registration->id,
            'student_id' => $registration->student_id,
            'email' => $registration->student?->email ?? $emailFallback ?? '',
            'formData' => $formData,
            'status' => $registration->status,
            'registration_type' => $registration->registration_type,
            'semester' => $registration->semester,
            'cccd_front_url' => $this->getImageUrl($registration->cccd_front_url),
            'cccd_back_url' => $this->getImageUrl($registration->cccd_back_url),
            'avatarUrl' => $this->getImageUrl($registration->avatar_url ?? $registration->student?->avatar),
            'father_name' => $registration->father_name,
            'father_birth_year' => $registration->father_birth_year,
            'father_phone' => $registration->father_phone,
            'father_job' => $registration->father_job,
            'mother_name' => $registration->mother_name,
            'mother_birth_year' => $registration->mother_birth_year,
            'mother_phone' => $registration->mother_phone,
            'mother_job' => $registration->mother_job,
            'parent_address' => $registration->parent_address,
            'emergency_contact_name' => $registration->emergency_contact_name,
            'emergency_contact_phone' => $registration->emergency_contact_phone,
            'emergency_contact_relationship' => $registration->emergency_contact_relationship,
            'stay_from_date' => $registration->stay_from_date,
            'stay_to_date' => $registration->stay_to_date,
            'commitment_confirm' => $registration->commitment_confirm,
            'reason' => $registration->rejection_reason,
            'rejection_reason' => $registration->rejection_reason,
            'auto_decision' => $registration->auto_decision,
            'auto_decision_reason' => $registration->auto_decision_reason,
            'decision_source' => $registration->decision_source,
            'manual_decision' => $registration->manual_decision,
            'manual_decision_reason' => $registration->manual_decision_reason,
            'source_dorm_reservation_id' => $registration->source_dorm_reservation_id,
            'registration_period_id' => $registration->registration_period_id,
            'channel' => $registration->period?->channel,
            'period_name' => $registration->period?->name,
            'period_status' => $registration->period?->status,
            'approved_at' => $registration->approved_at?->toDateString(),
            'cancelled_at' => $registration->cancelled_at?->toISOString(),
            'cancellation_reason' => $registration->cancellation_reason,
            'cancelled_by' => $registration->cancelled_by,
            'bed_selection_deadline' => $this->bedSelectionDeadline($registration)?->toDateTimeString(),
            'occupancy_id' => $registration->occupancy?->id,
            'assigned_room_id' => $registration->occupancy?->room_id,
            'assigned_bed_id' => $registration->occupancy?->bed_id,
            'bed_approval_status' => $registration->occupancy?->bed_approval_status,
            'occupancy_status' => $this->mapOccupancyStatus($registration->occupancy),
            'checkout_requested' => (bool) ($registration->occupancy?->pendingCheckoutRequest),
            'checkout_request' => $registration->occupancy?->pendingCheckoutRequest ? [
                'id' => $registration->occupancy->pendingCheckoutRequest->id,
                'reason' => $registration->occupancy->pendingCheckoutRequest->reason,
                'expected_leave_date' => $registration->occupancy->pendingCheckoutRequest->expected_leave_date?->toDateString(),
                'created_at' => $registration->occupancy->pendingCheckoutRequest->created_at,
            ] : null,
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
        if (! $isRoomChange) {
            $this->notifier()->notifyRoomAssigned(
                $student,
                $newRoomCode,
                $this->bedSelectionDeadline($registration),
                $registration->id,
                queue: true,
            );
            return;
        }

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

            $deadline = $this->bedSelectionDeadline($registration);
            if ($deadline) {
                $content .= " Hạn chót chọn giường: {$deadline->format('d/m/Y')}."
                    . ' Nếu quá hạn, hồ sơ đăng ký sẽ tự động bị huỷ và giường sẽ không được giữ lại — bạn sẽ cần đăng ký lại nếu vẫn muốn ở KTX.';
            }
        }

        $this->notifier()->notifyStudent($student, $title, $content, $type, queue: true);
    }

    /**
     * Hạn chọn giường = ngày đơn được duyệt + registration_periods.bed_selection_days.
     * Cùng công thức với SendBedSelectionRemindersCommand/ExpireBedSelectionCommand
     * để nội dung thông báo luôn khớp với thời điểm hệ thống thực sự huỷ hồ sơ.
     */
    private function bedSelectionDeadline(Registration $registration): ?Carbon
    {
        return $registration->bedSelectionDeadline();
    }

    private function notifier(): StudentNotificationService
    {
        return app(StudentNotificationService::class);
    }

    /**
     * `auto_decision_reason` do PriorityRankingService/process() sinh ra để ADMIN xem (kèm
     * thứ hạng, chỉ tiêu cụ thể — VD "Không đủ chỉ tiêu (nam) — xếp hạng thứ 3/3, chỉ tiêu 1
     * suất.") vốn được copy y nguyên sang `rejection_reason` gửi thẳng cho sinh viên — quá kỹ
     * thuật, lộ luôn thứ hạng/số liệu cạnh tranh nội bộ ra ngoài (báo cáo 28/07). Chỉ dịch lại
     * đúng mẫu do ranking sinh ra; các lý do khác (minh chứng ưu tiên không hợp lệ, admin tự
     * nhập tay...) vốn đã viết cho sinh viên đọc, giữ nguyên không đổi.
     */
    private function studentFacingRejectionReason(?string $autoDecisionReason): ?string
    {
        if ($autoDecisionReason && preg_match('/^Không đủ chỉ tiêu \((nam|nữ)\)/u', $autoDecisionReason, $m)) {
            return "Rất tiếc, đợt này ký túc xá đã hết chỗ dành cho sinh viên {$m[1]} nên hồ sơ của bạn chưa được duyệt lần này.";
        }

        return $autoDecisionReason;
    }

    /**
     * Đợt "đang mở nhận đơn" = status `active` HOẶC `processing` (mới xếp hạng thử để xem
     * trước, chưa phải quyết định cuối) MÀ CHƯA QUA HẠN 17:00 ngày `end_date` — tách biệt
     * "status" (nhãn nội bộ, đổi ngay khi admin bấm "Xếp hạng" dù chỉ để xem thử) khỏi "hạn
     * nhận đơn thật" (mốc đã hứa với sinh viên). Trước đây chỉ check status='active', khiến
     * sinh viên mất quyền nộp đơn ngay khi admin bấm Xếp hạng lần đầu — sớm hơn nhiều so với
     * hạn thật đã thông báo, dù "Xếp hạng" vốn làm lại được nhiều lần, không phải hành động
     * cuối (báo cáo 25/07). Đợt `closed` KHÔNG tính vào đây — mọi trường hợp closed hiện tại
     * đều đã qua hạn thật hoặc đã được admin chốt hẳn.
     */
    private function findOpenSubmissionPeriod(): ?RegistrationPeriod
    {
        return RegistrationPeriod::whereIn('status', ['active', 'processing'])
            ->orderBy('start_date', 'asc')
            ->get()
            ->first(function (RegistrationPeriod $period) {
                $deadline = $period->admissionDeadline();

                return $deadline === null || now()->lessThanOrEqualTo($deadline);
            });
    }

    /**
     * @param bool $queue Bật khi gọi trong vòng lặp duyệt hàng loạt (confirmBatch), tránh
     *                    chặn request chờ gửi email lần lượt từng đơn trong đợt.
     */
    private function notifyRegistrationDecision(Registration $registration, bool $queue = false): void
    {
        if ($registration->status === 'approved') {
            $this->notifier()->notifyStudent(
                $registration->student,
                'Đơn đăng ký nội trú đã được duyệt',
                'Đơn đăng ký nội trú KTX của bạn đã được duyệt. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
                'registration_approved',
                $registration->id,
                queue: $queue,
            );
        } elseif ($registration->status === 'rejected') {
            $reason = $registration->rejection_reason ? " Lý do: {$registration->rejection_reason}" : '';
            $this->notifier()->notifyStudent(
                $registration->student,
                'Đơn đăng ký nội trú bị từ chối',
                "Đơn đăng ký nội trú KTX của bạn đã bị từ chối.{$reason}",
                'registration_rejected',
                $registration->id,
                queue: $queue,
            );
        }
    }


    public function eligibility(Request $request): \Illuminate\Http\JsonResponse
    {
        // Danh tính lấy từ $request->user() (route đã bảo vệ auth:sanctum + role:student),
        // không nhận email từ client — tránh xem tình trạng đủ điều kiện của sinh viên khác.
        $account = $request->user();
        $student = Student::find($account->student_id);

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên'], 404);
        }

        // Check 1: Đã có đơn đang chờ xét duyệt TRONG ĐỢT ĐANG MỞ NHẬN ĐƠN
        // Chỉ chặn khi status='submitted' trong period đang mở nhận đơn (xem
        // findOpenSubmissionPeriod()). Đơn từ đợt cũ đã đóng (submitted/rejected) không
        // chặn sinh viên đăng ký đợt mới.
        $activePeriodId = $this->findOpenSubmissionPeriod()?->id;

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
                'redirect'       => '/student/extension',
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
        // Ưu tiên 1: đợt đang mở nhận đơn thật (active/processing-chưa-qua-hạn — xem
        // findOpenSubmissionPeriod()).
        $activePeriod = $this->findOpenSubmissionPeriod();

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

        // Check 7b: giới hạn theo nhóm đối tượng cấu hình trên đợt đăng ký.
        if ($targetError = $this->registrationPeriodTargetError($activePeriod, $student)) {
            return response()->json([
                'eligible'       => false,
                'reason_code'    => $targetError['reason_code'],
                'reason_message' => $targetError['message'],
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
        // Dùng request() thay vì injectRequest qua tham số method — index() cũng được gọi
        // nội bộ (không qua HTTP) từ StudentFaceSearchController::getStudentIdsListedInOccupancyManagement()
        // dưới dạng app(RegistrationController::class)->index(), request() vẫn resolve đúng
        // Request/Account của route hiện tại (khi đó luôn là admin) nên không ảnh hưởng.
        $account = request()->user();

        $query = Registration::with(['student', 'student.account', 'occupancy', 'occupancy.pendingCheckoutRequest', 'period']);

        // Route dùng chung role:admin,student. Sinh viên chỉ được xem danh sách đăng ký
        // trong CÙNG PHÒNG với mình (để hiển thị bạn cùng phòng ở "Phòng của tôi"),
        // không được xem toàn bộ danh sách đăng ký như admin.
        if ($account && $account->role === 'student') {
            $myRegistration = Registration::with('occupancy')
                ->where('student_id', $account->student_id)
                ->whereHas('occupancy', fn ($q) => $q->whereNotNull('room_id'))
                ->latest('id')
                ->first();

            $roomId = $myRegistration?->occupancy?->room_id;

            if (!$roomId) {
                return collect();
            }

            $query->whereHas('occupancy', fn ($q) => $q->where('room_id', $roomId));
        }

        // KHÔNG dedupe ở đây (đã thử rồi bỏ) — tab "Lịch sử" ở FE (AdminRegistrationsPage)
        // group các lần nộp trùng email từ CHÍNH mảng requests này (client-side, không gọi
        // API riêng), nên nếu lọc bớt ở đây thì lịch sử cũng mất luôn lần nộp cũ. Việc dedupe
        // hiển thị (chỉ hiện lần nộp mới nhất ở danh sách chính) được làm ở FE thay vì đây,
        // xem allMainItems/allRollingItems trong AdminRegistrationsPage.tsx.
        $registrations = $query->get();

        return $registrations->map(function ($registration) {
            return $this->formatRegistration($registration);
        });
    }

    public function store(StoreRegistrationRequest $request)
    {
        // Danh tính lấy từ $request->user() (route đã bảo vệ auth:sanctum + role:student),
        // không nhận email từ client — tránh nộp/ghi đè đơn đăng ký thay sinh viên khác.
        $account = $request->user();
        $student = Student::find($account->student_id);

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy user'], 404);
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

        $activePeriod = $this->findOpenSubmissionPeriod();
        if (!$activePeriod) {
            return response()->json(['message' => 'Hiện không có đợt đăng ký nào đang mở', 'reason_code' => 'no_open_channel'], 422);
        }

        // Re-check giới hạn năm học theo cấu hình đợt — xem giải thích ở eligibility() Check 7b.
        if ($targetError = $this->registrationPeriodTargetError($activePeriod, $student)) {
            return response()->json([
                'message'     => $targetError['message'],
                'reason_code' => $targetError['reason_code'],
            ], 422);
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
                    // Cha/mẹ + liên hệ khẩn cấp là dữ liệu tĩnh gắn với con người, không phải
                    // gắn với từng lần đăng ký — ghi (upsert) vào students mỗi lần nộp đơn để
                    // các lần đăng ký sau tự động điền sẵn (FE đọc qua /student/profile).
                    'father_name'       => $data['father_name'] ?? null,
                    'father_birth_year' => $data['father_birth_year'] ?? null,
                    'father_job'        => $data['father_job'] ?? null,
                    'father_phone'      => $data['father_phone'] ?? null,
                    'mother_name'       => $data['mother_name'] ?? null,
                    'mother_birth_year' => $data['mother_birth_year'] ?? null,
                    'mother_job'        => $data['mother_job'] ?? null,
                    'mother_phone'      => $data['mother_phone'] ?? null,
                    'parent_address'    => $data['parent_address'] ?? null,
                    'emergency_contact_name'         => $data['emergency_contact_name'] ?? null,
                    'emergency_contact_phone'        => $data['emergency_contact_phone'] ?? null,
                    'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
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
                    // Snapshot lịch sử tại thời điểm nộp đơn — ĐỌC LẠI từ $student (vừa
                    // update() ở trên) thay vì tin thẳng $data do FE gửi lên, vì input dù
                    // đã set readonly ở FE vẫn có thể bị sửa value qua devtools rồi gửi lên.
                    'father_name'            => $student->father_name,
                    'father_birth_year'      => $student->father_birth_year,
                    'father_job'             => $student->father_job,
                    'father_phone'           => $student->father_phone,
                    'mother_name'            => $student->mother_name,
                    'mother_birth_year'      => $student->mother_birth_year,
                    'mother_job'             => $student->mother_job,
                    'mother_phone'           => $student->mother_phone,
                    'parent_address'         => $student->parent_address,
                    'emergency_contact_name'         => $student->emergency_contact_name,
                    'emergency_contact_phone'        => $student->emergency_contact_phone,
                    'emergency_contact_relationship' => $student->emergency_contact_relationship,
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

        // Minh chứng ưu tiên (tối đa 6 ảnh / tiêu chí): chỉ chuyển file vào disk 'local'
        // (staging, luôn ghi nhanh) rồi xử lý lưu vào vị trí lưu trữ cuối + tạo bản ghi
        // evidence NGAY (dispatchSync) — job này không dùng queue worker vì cần ghi vào
        // Railway volume, chỉ service web (nơi có mount volume) mới truy cập được. Việc
        // này nhẹ (1-6 ảnh) nên chạy đồng bộ không đáng kể, không giống các job khác
        // (gửi mail hàng loạt, đồng bộ Rekognition) chạy qua queue worker riêng.
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

                $stagingDir = 'temp_evidence/' . $registrationId . '_' . $criteriaId;
                $stagedFilenames = [];
                foreach ($files as $index => $file) {
                    $stagedName = 'evidence_' . $index . '.' . $file->getClientOriginalExtension();
                    $file->storeAs($stagingDir, $stagedName, 'local');
                    $stagedFilenames[] = $stagedName;
                }

                // Đơn đăng ký đã tạo thành công (transaction ở trên đã commit) — lỗi lưu
                // minh chứng ở bước này (vd. volume tạm gián đoạn) không được làm hỏng
                // response, chỉ log + báo sinh viên vào lại trang để tải lại minh chứng.
                try {
                    ProcessPriorityEvidenceJob::dispatchSync($registrationId, $criteriaId, $stagingDir, $stagedFilenames);
                } catch (\Throwable $exception) {
                    Log::error('Lưu minh chứng ưu tiên thất bại ngay sau khi nộp đơn', [
                        'registration_id' => $registrationId,
                        'priority_criteria_id' => $criteriaId,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->notifier()->notifyStudent(
                        $result['student'],
                        'Lỗi tải ảnh minh chứng ưu tiên',
                        'Hệ thống chưa thể lưu một hoặc nhiều ảnh minh chứng cho diện ưu tiên bạn đã đăng ký. Vui lòng vào lại trang đăng ký nội trú để kiểm tra và tải lại ảnh minh chứng.',
                        'priority_evidence_failed',
                        $registrationId,
                        queue: true,
                    );
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
        $semester = $request->query('semester');

        // Danh tính lấy từ $request->user() (route đã bảo vệ auth:sanctum + role:student),
        // không nhận email từ client — tránh xem đơn đăng ký của sinh viên khác.
        $account = $request->user();

        if (!$account->student_id) {
            return response()->json(null);
        }

        $student = Student::find($account->student_id);

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

        return response()->json($this->formatRegistration($registration, $student?->email));
    }

    /** Message lỗi capacity dùng chung — nhất quán giữa các endpoint duyệt (BUG-2/RR-2/RR-3). */
    private const CAPACITY_CONFLICT_MESSAGE = 'Không thể duyệt hồ sơ vì sức chứa KTX đã thay đổi hoặc không còn suất trống. Vui lòng tải lại dữ liệu.';

    public function approve($id, Request $request)
    {
        // Lock order thống nhất: RegistrationPeriod trước, rồi Registration.
        $result = DB::transaction(function () use ($id) {
            $registration = Registration::with('student')->lockForUpdate()->find($id);

            if (!$registration) {
                return ['ok' => false, 'status' => 404, 'payload' => ['message' => 'Không tìm thấy đơn']];
            }

            $period = $registration->registration_period_id
                ? RegistrationPeriod::where('id', $registration->registration_period_id)->lockForUpdate()->first()
                : null;

            // Re-check trạng thái SAU khi đã lock — không cho duyệt lại đơn đã cancelled/
            // rejected (idempotent: trả lỗi rõ, không âm thầm đổi trạng thái).
            if ($registration->status === 'cancelled') {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Đơn đã bị hủy, không thể duyệt.']];
            }
            if ($registration->status === 'rejected') {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Đơn đã bị từ chối, không thể duyệt.']];
            }

            // Minh chứng ưu tiên bị từ chối — không được duyệt (thường status đã tự chuyển
            // 'rejected' ngay khi admin từ chối minh chứng; check này phòng vệ thêm với dữ
            // liệu cũ chưa cascade).
            if ($registration->hasRejectedPriorityEvidence()) {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Không thể duyệt hồ sơ vì minh chứng ưu tiên không hợp lệ.']];
            }

            // Registration nguồn giữ chỗ (tân sinh viên) — suất đã được DormReservation
            // approved giữ từ trước, KHÔNG tính đây là chiếm thêm suất mới nên bỏ qua check
            // capacity thường. Thay vào đó phải lock + xác nhận DormReservation nguồn vẫn
            // còn approved.
            $sourceReservation = null;
            if ($registration->status !== 'approved' && $registration->source_dorm_reservation_id) {
                $sourceReservation = DormReservation::where('id', $registration->source_dorm_reservation_id)
                    ->lockForUpdate()
                    ->first();

                if (!$sourceReservation || $sourceReservation->status !== 'approved') {
                    return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Hồ sơ giữ chỗ nguồn không còn ở trạng thái approved (có thể đã bị thay đổi bởi thao tác khác). Vui lòng tải lại dữ liệu.']];
                }
            } elseif ($registration->status !== 'approved') {
                // submitted -> approved là hành động thật sự chiếm thêm 1 suất committed nên cần
                // check capacity; nếu đã approved sẵn (double-click/gọi lại) thì đây là no-op, không
                // chiếm thêm suất nào nên không cần re-check.
                $capacity = app(DormCapacityService::class)
                    ->summarizeForRegistrationPeriod($period ?? $registration->registration_period_id, gender: $registration->student?->gender);
                if (($capacity['available_approval_slots'] ?? 0) < 1) {
                    return ['ok' => false, 'status' => 422, 'payload' => ['message' => self::CAPACITY_CONFLICT_MESSAGE, 'capacity' => $capacity]];
                }
            }

            $registration->status = 'approved';
            $registration->approved_at = now();
            $registration->save();

            if ($sourceReservation) {
                $sourceReservation->update([
                    'status'                    => 'converted',
                    'converted_registration_id' => $registration->id,
                ]);
            }

            return ['ok' => true, 'registration' => $registration];
        });

        if (!$result['ok']) {
            return response()->json($result['payload'], $result['status']);
        }

        // Gửi thông báo SAU khi transaction đã commit.
        $registration = $result['registration'];
        if ($registration->student) {
            $this->notifyRegistrationDecision($registration, queue: true);
        }

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

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'Đơn đã bị hủy, không thể phân phòng.'], 422);
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
            'bed_id' => 'required|integer|exists:beds,id',
        ]);

        // Danh tính lấy từ $request->user() (route đã bảo vệ auth:sanctum + role:student),
        // không nhận email từ client — tránh chọn giường thay sinh viên khác.
        $account = $request->user();

        if (!$account->student_id) {
            return response()->json(['message' => 'Không tìm thấy user hoặc chưa liên kết sinh viên'], 404);
        }

        $registration = Registration::with(['occupancy', 'period', 'student'])->where('student_id', $account->student_id)
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn đăng ký'], 404);
        }

        $deadline = $this->bedSelectionDeadline($registration);
        if ($deadline && now()->greaterThanOrEqualTo($deadline)) {
            return response()->json(['message' => 'Đã quá hạn chọn giường. Vui lòng liên hệ ban quản lý KTX.'], 422);
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

        // 4. Giường chưa được sinh viên khác giữ chỗ hoặc đang ở. Sinh viên lứa cũ chưa gia hạn
        // (check_out_date < stay_start_date đợt này) coi như sẽ trống kịp, không chặn chọn
        // giường của lứa mới (xem báo cáo timing 13/08) — chỉ áp dụng khi đơn có period rõ ràng.
        $asOfDate = $registration->period?->stay_start_date?->toDateString();
        $isOccupiedByAnotherStudent = Occupancy::query()
            ->occupiedBeds($asOfDate)
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

        if ($registration->student) {
            $bed->loadMissing('room.floor');
            $roomCode = ($bed->room?->floor?->building_code ?? '') . ($bed->room?->room_number ?? '');
            $dueDateText = $bill?->due_date ? Carbon::parse($bill->due_date)->format('d/m/Y') : null;

            $this->notifier()->notifyStudent(
                $registration->student,
                'Đã chọn giường thành công',
                "Bạn đã chọn Phòng {$roomCode} giường #{$bed->bed_number}."
                    . ($dueDateText ? " Vui lòng thanh toán hóa đơn tháng đầu trước hạn {$dueDateText} để hoàn tất thủ tục lưu trú." : ' Vui lòng thanh toán hóa đơn tháng đầu để hoàn tất thủ tục lưu trú.'),
                'bed_selected',
                $registration->id,
                queue: true,
            );
        }

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

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'Đơn đã bị hủy, không thể kích hoạt lưu trú.'], 422);
        }

        $occupancy = $registration->occupancy;

        if (!$occupancy || !$occupancy->bed_id || $occupancy->status === 'CANCELLED') {
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

        if ($registration->student) {
            $this->notifier()->notifyStudent(
                $registration->student,
                'Lưu trú đã được kích hoạt',
                'Chỗ ở của bạn tại KTX đã được kích hoạt. Chúc bạn có thời gian lưu trú thoải mái.',
                'occupancy_activated',
                $registration->id,
                queue: true,
            );
        }

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy'])));
    }

    public function rejectBed($id)
    {
        $registration = Registration::with(['student', 'student.account', 'occupancy'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'Đơn đã bị hủy, không thể từ chối giường.'], 422);
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

        if ($registration->student) {
            $this->notifier()->notifyStudent(
                $registration->student,
                'Giường đã chọn bị từ chối',
                'Giường bạn vừa chọn không được chấp nhận. Vui lòng đăng nhập hệ thống để chọn lại giường khác.',
                'bed_rejected',
                $registration->id,
                queue: true,
            );
        }

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy'])));
    }

    public function requestCheckout(Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
            'expected_leave_date' => 'required|date|after_or_equal:today',
        ]);

        // Danh tính lấy từ $request->user() (route đã bảo vệ auth:sanctum + role:student),
        // không nhận email từ client — tránh gửi yêu cầu thôi ở thay sinh viên khác.
        $account = $request->user();

        if (!$account->student_id) {
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

        // Ngày dự kiến rời phải trước ngày kết thúc lưu trú hiện tại (hạn hợp đồng gốc).
        if ($occupancy->check_out_date
            && !Carbon::parse($request->expected_leave_date)->lt(Carbon::parse($occupancy->check_out_date))) {
            return response()->json([
                'message' => 'Ngày rời phải trước ngày kết thúc lưu trú dự kiến (' . Carbon::parse($occupancy->check_out_date)->format('d/m/Y') . ').',
            ], 422);
        }

        // Mỗi occupancy chỉ có 1 yêu cầu thôi ở đang chờ duyệt.
        $hasPending = CheckoutRequest::where('occupancy_id', $occupancy->id)
            ->where('status', 'pending')
            ->exists();
        if ($hasPending) {
            return response()->json(['message' => 'Đã có yêu cầu thôi ở đang chờ duyệt.'], 422);
        }

        // Không cho gửi yêu cầu thôi ở khi đang có yêu cầu gia hạn lưu trú chờ duyệt.
        $hasPendingExtension = OccupancyExtension::where('occupancy_id', $occupancy->id)
            ->where('status', 'pending')
            ->exists();
        if ($hasPendingExtension) {
            return response()->json(['message' => 'Bạn đang có yêu cầu gia hạn lưu trú đang chờ duyệt, vui lòng xử lý xong yêu cầu đó trước.'], 422);
        }

        // Student requested checkout; occupancy stays ACTIVE (kể cả check_out_date) cho tới khi admin confirm.
        CheckoutRequest::create([
            'occupancy_id' => $occupancy->id,
            'student_id' => $occupancy->student_id ?? $account->student_id,
            'reason' => $request->reason,
            'expected_leave_date' => $request->expected_leave_date,
            'status' => 'pending',
        ]);

        if ($registration->student) {
            AdminNotification::create([
                'title' => 'Yêu cầu thôi ở mới',
                'content' => "Sinh viên {$registration->student->full_name} ({$registration->student->student_code}) vừa gửi yêu cầu thôi ở.",
                'type' => 'checkout_requested',
                'related_id' => $occupancy->id,
                'created_at' => now(),
            ]);
        }

        return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy', 'occupancy.pendingCheckoutRequest'])));
    }

    public function cancelCheckout(Request $request)
    {
        $account = $request->user();

        if (!$account->student_id) {
            return response()->json(['message' => 'Không tìm thấy user hoặc chưa liên kết sinh viên'], 404);
        }

        $registration = Registration::with(['student', 'student.account', 'occupancy'])
            ->where('student_id', $account->student_id)
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        if (!$registration || !$registration->occupancy) {
            return response()->json(['message' => 'Sinh viên chưa có thông tin lưu trú.'], 404);
        }

        $checkoutRequest = CheckoutRequest::where('occupancy_id', $registration->occupancy->id)
            ->where('status', 'pending')
            ->first();

        if (!$checkoutRequest) {
            return response()->json(['message' => 'Không có yêu cầu thôi ở nào đang chờ duyệt.'], 404);
        }

        $checkoutRequest->update(['status' => 'cancelled', 'processed_at' => now()]);

        if ($registration->student) {
            AdminNotification::create([
                'title' => 'Sinh viên đã hủy yêu cầu thôi ở',
                'content' => "Sinh viên {$registration->student->full_name} ({$registration->student->student_code}) đã hủy yêu cầu thôi ở trước đó.",
                'type' => 'checkout_requested',
                'related_id' => $registration->occupancy->id,
                'created_at' => now(),
            ]);
        }

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

            // Ngày rời thực tế = ngày sinh viên đã đề nghị trong yêu cầu thôi ở (nếu có),
            // vì check_out_date của occupancy không còn bị ghi đè lúc gửi yêu cầu nữa.
            $pendingCheckout = CheckoutRequest::where('occupancy_id', $occupancy->id)
                ->where('status', 'pending')
                ->first();

            $occupancy->status         = 'COMPLETED';
            $occupancy->check_out_date = $pendingCheckout?->expected_leave_date?->toDateString() ?? now()->toDateString();
            $occupancy->save();

            // Chốt yêu cầu thôi ở đang chờ (nếu có).
            if ($pendingCheckout) {
                $pendingCheckout->update(['status' => 'approved', 'processed_at' => now()]);
            }

            $bed = Bed::find($occupancy->bed_id);
            if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
                $bed->status = 'active';
                $bed->save();
            }
        });

        if ($registration->student) {
            $this->notifier()->notifyStudent(
                $registration->student,
                'Đã hoàn tất thủ tục thôi ở',
                'Yêu cầu thôi ở của bạn tại KTX đã được xác nhận hoàn tất.',
                'checkout_completed',
                $registration->id,
                queue: true,
            );
        }

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

        if ($registration->student) {
            $this->notifier()->notifyStudent(
                $registration->student,
                'Buộc thôi ở tại KTX',
                'Bạn đã bị buộc thôi ở tại KTX. Lý do: ' . $request->input('reason'),
                'force_checkout',
                $registration->id,
                queue: true,
            );
        }

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

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'Đơn đã bị hủy, không thể từ chối.'], 422);
        }

        // Registration nguồn giữ chỗ (tân sinh viên) đã có suất được DormReservation approved
        // giữ sẵn — từ chối thẳng ở đây sẽ để lại DormReservation vẫn approved trong khi
        // Registration đã rejected (mâu thuẫn dữ liệu, suất bị kẹt không giải phóng được cho
        // waitlist). Tạm chặn, cần xử lý tại hồ sơ giữ chỗ tân sinh viên trước.
        if ($registration->source_dorm_reservation_id && $registration->status === 'submitted') {
            return response()->json([
                'message' => 'Hồ sơ này đã có suất giữ chỗ được duyệt từ hồ sơ giữ chỗ tân sinh viên. Không thể từ chối qua chức năng này — vui lòng xử lý tại hồ sơ giữ chỗ tân sinh viên trước.',
            ], 422);
        }

        $registration->status = 'rejected';
        $registration->rejection_reason = $request->input('rejectionReason');
        $registration->save();

        if ($registration->student) {
            $this->notifyRegistrationDecision($registration, queue: true);
        }

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

        // Route dùng chung role:admin,student. Admin xem được mọi đơn; sinh viên chỉ được
        // xem đúng đơn của chính mình — tránh xem đơn đăng ký của sinh viên khác qua ID.
        $account = request()->user();
        if ($account && $account->role === 'student' && (int) $registration->student_id !== (int) $account->student_id) {
            return response()->json(['message' => 'Bạn không có quyền xem đơn đăng ký này.'], 403);
        }

        return response()->json($this->formatRegistration($registration));
    }

    public function getRegistrationHistory(Request $request, $email, $semester = null)
    {
        // Danh tính lấy từ $request->user() (route đã bảo vệ auth:sanctum + role:student),
        // không nhận email từ URL — {email} giữ nguyên trên route để không đổi URL, nhưng
        // không còn dùng để tra cứu, tránh xem lịch sử đăng ký của sinh viên khác.
        $account = $request->user();

        if (!$account->student_id) {
            Log::info("RegistrationController.getRegistrationHistory - student not found");
            return response()->json([]);
        }

        // Không truyền {semester} → trả toàn bộ lịch sử đăng ký (mọi kỳ) của sinh viên, mới nhất trước.
        $registrations = Registration::with(['student', 'student.account', 'occupancy', 'occupancy.pendingCheckoutRequest'])
            ->where('student_id', $account->student_id)
            ->when($semester !== null, fn ($q) => $q->where('semester', $semester))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        Log::info("RegistrationController.getRegistrationHistory - found registrations", [
            'count' => $registrations->count(),
            'student_id' => $account->student_id,
        ]);

        return $registrations->map(function ($registration) {
            return $this->formatRegistration($registration);
        });
    }

    /**
     * "Đổi đề xuất" thủ công của admin trên 1 đơn đang chờ xử lý (status='submitted').
     *
     * - decision=reject: CHỐT THẬT NGAY LẬP TỨC (status='rejected'), không còn là đề xuất chờ
     *   Xác nhận riêng nữa — xem manualRejectNow(). Đơn này bị loại vĩnh viễn khỏi vòng xếp
     *   hạng (PriorityRankingService::rankPeriod() loại status='rejected'), suất tự động trôi
     *   xuống người xếp hạng kế tiếp cùng giới ở lần "Xếp hạng lại" kế tiếp.
     * - decision=approve: chỉ là "ghim tay" (decision_source='manual'), vẫn ở status='submitted'
     *   chờ Xác nhận như bình thường. Lần "Xếp hạng lại" kế tiếp sẽ ưu tiên giữ suất cho đơn
     *   này TRƯỚC (Cách A — có thể đẩy 1 người xếp hạng tự nhiên cao hơn xuống waitlist) — xem
     *   PriorityRankingService::splitBucketWithManualPins(). FE nên gọi previewManualApprove()
     *   trước để cảnh báo admin ai có thể bị ảnh hưởng.
     * - decision=review: giữ nguyên hành vi cũ (chỉ đánh dấu "cần xem lại", không phải quyết
     *   định tay/ghim gì cả).
     */
    public function patchAutoDecision($id, Request $request)
    {
        $request->validate([
            'decision' => 'required|in:approve,reject,review',
            // Chỉ bắt buộc lý do cho 'reject' (giờ chốt thật ngay, cần lưu vết) — 'approve' để
            // nullable vì còn 1 nơi gọi không qua dialog "Đổi đề xuất": nút "Duyệt" nhanh ở khu
            // "Chờ duyệt thủ công" (handleApproveFromReview ở FE) duyệt thẳng rồi confirm luôn,
            // không đi qua dialog ghim có ô nhập lý do bắt buộc.
            'reason'   => 'required_if:decision,reject|nullable|string|max:1000',
        ]);

        $decision = $request->input('decision');

        if ($decision === 'reject') {
            return $this->manualRejectNow($id, $request->input('reason'));
        }

        $registration = Registration::with(['student', 'student.account', 'occupancy', 'period'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        // Chỉ cho đổi đề xuất khi đơn còn đang chờ xử lý — status khác 'submitted' (đã
        // approved/rejected/cancelled) nghĩa là đã có quyết định cuối cùng hoặc đã bị hủy, đổi
        // đề xuất lúc này không còn ý nghĩa gì (và với reject giờ đụng thẳng status, càng cần
        // chặn chặt để không đảo ngược 1 quyết định đã chốt/hủy trước đó).
        if ($registration->status !== 'submitted') {
            return response()->json(['message' => 'Chỉ có thể đổi đề xuất khi đơn đang ở trạng thái chờ xử lý.'], 422);
        }

        if ($decision === 'approve') {
            if ($registration->hasRejectedPriorityEvidence()) {
                return response()->json(['message' => 'Không thể duyệt hồ sơ vì minh chứng ưu tiên không hợp lệ.'], 422);
            }

            // KHÔNG còn chặn theo "sức chứa còn trống hay không" ở đây nữa — đó là rào chắn
            // của thời trước khi có ghim tay, giờ mâu thuẫn trực tiếp với chính mục đích của
            // ghim tay: được phép chiếm 1 suất TRƯỚC dù chỉ tiêu đã đủ người, đổi lại 1 người
            // đang giữ suất yếu nhất bị đẩy xuống waitlist (đã cảnh báo rõ qua
            // previewManualApprove() + dialog FE trước khi admin xác nhận).
            //
            // Ghim tay là hành động MỘT LẦN — PriorityRankingService::applyManualApprove() tự
            // xử lý trực tiếp: xác định ai bị đẩy (nếu có), ghi kết quả CHO CẢ 2 người ngay lập
            // tức, không cần "Xếp hạng lại" toàn bộ để tính thắng thua (xem
            // splitBucketWithManualPins() — sau khi thắng, đơn này được khóa cứng, không còn bị
            // tính lại/tranh chấp ở các lần xếp hạng sau nữa).
            $gender = strtolower($registration->student?->gender ?? '');
            if (!in_array($gender, ['male', 'female'], true)) {
                return response()->json(['message' => 'Không xác định được giới tính của sinh viên.'], 422);
            }

            $capacityService = app(DormCapacityService::class);
            $beds = (int) ($capacityService->summarizeForRegistrationPeriod(
                $registration->registration_period_id,
                gender: $gender,
                excludeReservationsWithRegistration: true,
            )['available_approval_slots'] ?? 0);

            (new PriorityRankingService())->applyManualApprove($registration, $beds, $request->input('reason'));

            return response()->json($this->formatRegistration($registration->fresh(['student', 'student.account', 'occupancy', 'period'])));
        }

        // review — giữ hành vi cũ, chỉ là nhãn "cần xem lại", không ghim gì.
        $registration->decision_source = null;
        $registration->manual_decision = null;
        $registration->manual_decision_reason = null;
        $registration->auto_decision = 'review';
        $registration->auto_decision_reason = $request->input('reason');
        $registration->save();

        return response()->json($this->formatRegistration($registration));
    }

    /**
     * Từ chối tay = chốt thật ngay, không qua bước "Xác nhận" riêng nữa. Nếu đơn có nguồn
     * giữ chỗ tân sinh viên (source_dorm_reservation_id), reservation nguồn chuyển hẳn sang
     * 'rejected' (KHÔNG phải 'waitlisted' như nhánh reject-do-thua-điểm-xếp-hạng của
     * confirmSingle()) — vì đây là admin chủ động xác định hồ sơ có vấn đề, không phải đơn
     * thuần túy thua điểm cạnh tranh; 'rejected' không nằm trong ACTIVE_RESERVATION_STATUSES
     * (xem DormReservationController) nên thí sinh nộp lại hồ sơ mới được, không bị kẹt.
     */
    private function manualRejectNow(int $id, string $reason): JsonResponse
    {
        $result = DB::transaction(function () use ($id, $reason) {
            $registration = Registration::with(['student', 'student.account', 'occupancy', 'period'])
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$registration) {
                return response()->json(['message' => 'Không tìm thấy đơn'], 404);
            }

            if ($registration->status !== 'submitted') {
                return response()->json(['message' => 'Chỉ có thể đổi đề xuất khi đơn đang ở trạng thái chờ xử lý.'], 422);
            }

            $sourceReservation = null;
            if ($registration->source_dorm_reservation_id) {
                $sourceReservation = DormReservation::where('id', $registration->source_dorm_reservation_id)
                    ->lockForUpdate()
                    ->first();
            }

            $registration->status = 'rejected';
            $registration->rejection_reason = $reason;
            $registration->auto_decision = 'reject';
            $registration->auto_decision_reason = $reason;
            $registration->decision_source = 'manual';
            $registration->manual_decision = 'reject';
            $registration->manual_decision_reason = $reason;
            $registration->save();

            if ($sourceReservation && $sourceReservation->status === 'approved') {
                $sourceReservation->update([
                    'status'               => 'rejected',
                    'rejection_reason'     => $reason,
                    'auto_decision'        => null,
                    'auto_decision_reason' => null,
                ]);
            }

            // Đơn vừa bị loại khỏi vòng cạnh tranh (status='rejected') — xếp hạng lại NGAY
            // đúng giới của đơn này để người kế tiếp trong waitlist được đôn lên và hiển thị
            // đúng trên giao diện luôn, không cần đợi admin bấm "Xếp hạng lại" thủ công.
            $this->refreshGenderRanking($registration->registration_period_id, strtolower($registration->student?->gender ?? ''));

            return ['registration' => $registration];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $registration = $result['registration'];

        if ($registration->student) {
            $this->notifyRegistrationDecision($registration, queue: true);
        }

        return response()->json($this->formatRegistration($registration));
    }

    /**
     * Xếp hạng lại NGAY 1 giới của 1 đợt — dùng cho các thao tác tự động loại 1 đơn khỏi cạnh
     * tranh (Từ chối tay...) để người kế tiếp được đôn lên và hiển thị đúng ngay lập tức, không
     * cần đợi admin bấm "Xếp hạng lại" thủ công. Bỏ qua nếu $gender không hợp lệ (dữ liệu sinh
     * viên thiếu giới tính — trường hợp bất thường, không nên chặn cả giao dịch chính vì lý do
     * phụ này).
     */
    private function refreshGenderRanking(int $periodId, string $gender): void
    {
        if (!in_array($gender, ['male', 'female'], true)) {
            return;
        }

        $capacityService = app(DormCapacityService::class);
        $availableBedsByGender = [
            'male' => (int) ($capacityService->summarizeForRegistrationPeriod($periodId, gender: 'male', excludeReservationsWithRegistration: true)['available_approval_slots'] ?? 0),
            'female' => (int) ($capacityService->summarizeForRegistrationPeriod($periodId, gender: 'female', excludeReservationsWithRegistration: true)['available_approval_slots'] ?? 0),
        ];

        (new PriorityRankingService())->applyRankingDecisions($periodId, $availableBedsByGender, onlyGender: $gender);
    }

    /**
     * Xem trước hệ quả nếu ghim tay Duyệt cho 1 đơn — trả về sinh viên (nếu có) sẽ bị đẩy
     * xuống waitlist, để FE cảnh báo admin trước khi xác nhận. Không ghi gì xuống DB.
     */
    public function previewManualApprove($id): JsonResponse
    {
        $registration = Registration::find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        if ($registration->status !== 'submitted') {
            return response()->json(['message' => 'Chỉ có thể đổi đề xuất khi đơn đang ở trạng thái chờ xử lý.'], 422);
        }

        $capacityService = app(DormCapacityService::class);
        $availableBedsByGender = [];
        foreach (['male', 'female'] as $g) {
            $capacity = $capacityService->summarizeForRegistrationPeriod(
                $registration->registration_period_id,
                gender: $g,
                excludeReservationsWithRegistration: true,
            );
            $availableBedsByGender[$g] = (int) ($capacity['available_approval_slots'] ?? 0);
        }

        $preview = (new PriorityRankingService())->previewManualApprove(
            (int) $id,
            $registration->registration_period_id,
            $availableBedsByGender,
        );

        if ($preview === null) {
            return response()->json(['message' => 'Không xác định được giới tính của sinh viên.'], 422);
        }

        $bumped = $preview['bumped'];

        return response()->json([
            'bumped_student' => $bumped ? [
                'full_name'    => $bumped->student?->full_name,
                'student_code' => $bumped->student?->student_code,
            ] : null,
        ]);
    }

    public function confirmSingle($id)
    {
        $registration = Registration::with(['student', 'student.account', 'occupancy', 'period'])->find($id);

        if (!$registration) {
            return response()->json(['message' => 'Không tìm thấy đơn'], 404);
        }

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'Đơn đã bị hủy, không thể xác nhận quyết định tự động.'], 422);
        }

        if (!in_array($registration->auto_decision, ['approve', 'reject'])) {
            return response()->json(['message' => 'Đơn chưa có quyết định tự động (auto_decision phải là approve hoặc reject)'], 422);
        }

        $result = DB::transaction(function () use ($id) {
            $registration = Registration::with(['student', 'student.account', 'occupancy', 'period'])
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$registration) {
                return response()->json(['message' => 'Không tìm thấy đơn'], 404);
            }

            RegistrationPeriod::whereKey($registration->registration_period_id)->lockForUpdate()->first();

            if ($registration->auto_decision === 'approve' && $registration->hasRejectedPriorityEvidence()) {
                return response()->json(['message' => 'Không thể duyệt hồ sơ vì minh chứng ưu tiên không hợp lệ.'], 422);
            }

            // Registration nguồn giữ chỗ (tân sinh viên) — cần lock + đọc lại DormReservation
            // nguồn dù quyết định là approve hay reject, vì từ khi rankPeriod() xếp hạng CHUNG
            // với đăng ký thường (báo cáo 24/07), 1 hồ sơ giữ chỗ đã "approved" trước đó vẫn có
            // thể bị xếp lại thành 'reject' nếu thua điểm ưu tiên — không còn là suất bất biến.
            $sourceReservation = null;
            if ($registration->source_dorm_reservation_id && in_array($registration->auto_decision, ['approve', 'reject'], true)) {
                $sourceReservation = DormReservation::where('id', $registration->source_dorm_reservation_id)
                    ->lockForUpdate()
                    ->first();

                if (!$sourceReservation || $sourceReservation->status !== 'approved') {
                    return response()->json([
                        'message' => 'Hồ sơ giữ chỗ nguồn không còn ở trạng thái approved (có thể đã bị thay đổi bởi thao tác khác). Vui lòng tải lại dữ liệu.',
                    ], 422);
                }
            } elseif ($registration->auto_decision === 'approve' && $registration->status !== 'approved') {
                $capacity = app(DormCapacityService::class)->summarizeForRegistrationPeriod($registration->registration_period_id, gender: $registration->student?->gender);
                if (($capacity['available_approval_slots'] ?? 0) < 1) {
                    return response()->json([
                        'message'  => 'Sức chứa KTX đã thay đổi. Hiện không còn suất để duyệt thêm đơn này. Vui lòng xếp hạng hoặc điều chỉnh lại.',
                        'capacity' => $capacity,
                    ], 422);
                }
            }

            $registration->status = $registration->auto_decision === 'approve' ? 'approved' : 'rejected';
            if ($registration->status === 'approved') {
                $registration->approved_at = now();
            }
            if ($registration->status === 'rejected' && $registration->auto_decision_reason) {
                $registration->rejection_reason = $this->studentFacingRejectionReason($registration->auto_decision_reason);
            }
            $registration->save();

            $reservationBumped = false;
            if ($sourceReservation) {
                if ($registration->status === 'approved') {
                    // Chính thức chuyển DormReservation nguồn sang converted NGAY LÚC NÀY
                    // (không phải lúc tạo Registration) — suất chuyển từ counter
                    // approved_dorm_reservations sang approved_unassigned_registrations, tổng
                    // available_approval_slots không đổi.
                    $sourceReservation->update([
                        'status'                    => 'converted',
                        'converted_registration_id' => $registration->id,
                    ]);
                } else {
                    // Thua điểm ưu tiên ở vòng xếp hạng chung — trả hồ sơ giữ chỗ về danh sách
                    // chờ (KHÔNG rejected hẳn, vì thí sinh vẫn có thể được đôn lại nếu còn suất
                    // trống, giống mọi hồ sơ waitlisted khác). Trang tra cứu của thí sinh đọc
                    // trực tiếp status này nên tự động cập nhật theo, không cần sửa thêm.
                    $sourceReservation->update([
                        'status'               => 'waitlisted',
                        'auto_decision'        => null,
                        'auto_decision_reason' => null,
                    ]);
                    $reservationBumped = true;
                }
            }

            return ['registration' => $registration, 'reservation_bumped' => $reservationBumped];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $registration = $result['registration'];

        if ($registration->student) {
            $this->notifyRegistrationDecision($registration, queue: true);

            if ($result['reservation_bumped']) {
                $this->notifier()->notifyStudent(
                    $registration->student,
                    'Hồ sơ giữ chỗ KTX chuyển sang danh sách chờ',
                    'Khi xếp hạng chung toàn đợt (gồm cả đăng ký thường), hồ sơ giữ chỗ KTX của bạn không đủ thứ hạng ưu tiên để giữ suất và đã chuyển sang danh sách chờ. Bạn vẫn có thể được đôn lại nếu còn suất trống — vui lòng theo dõi thông báo tiếp theo.',
                    'dorm_reservation_waitlisted',
                    $registration->source_dorm_reservation_id,
                    queue: true,
                );
            }
        }

        return response()->json($this->formatRegistration($registration));
    }

    public function confirmBatch($periodId)
    {
        // Lock order thống nhất: RegistrationPeriod trước, rồi tới tập Registration (theo id
        // tăng dần) — toàn bộ batch trong 1 transaction, không save() rời rạc ngoài transaction.
        try {
        $result = DB::transaction(function () use ($periodId) {
            $period = RegistrationPeriod::where('id', $periodId)->lockForUpdate()->first();

            if (!$period) {
                return ['ok' => false, 'status' => 404, 'payload' => ['message' => 'Không tìm thấy đợt đăng ký']];
            }

            if ($period->channel !== 'main') {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Chỉ áp dụng cho đợt kênh chính (main)']];
            }

            if ($period->status !== 'processing') {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Đợt phải đang ở trạng thái processing']];
            }

            // Chặn xác nhận TRƯỚC 17:00 ngày end_date — "Xếp hạng" chỉ để xem thử, có thể
            // chạy lại nhiều lần, KHÔNG phải quyết định cuối; còn "Xác nhận tất cả" mới thật
            // sự chốt (không thể đảo ngược) VÀ tự chuyển period → closed, khiến sinh viên mất
            // quyền nộp đơn sớm hơn hạn 17h đã hứa nếu admin bấm sớm (báo cáo 25/07). Không
            // có ngoại lệ bypass — muốn đóng sớm hơn dự kiến thì phải sửa end_date của đợt
            // trước, không né qua chặn này.
            $deadline = $period->admissionDeadline();
            if ($deadline && now()->lessThan($deadline)) {
                return ['ok' => false, 'status' => 422, 'payload' => [
                    'message' => "Chưa tới hạn 17:00 ngày {$deadline->format('d/m/Y')} — chưa thể xác nhận đóng đợt.",
                ]];
            }

            // Không còn chặn cứng "Xác nhận tất cả" vì còn hồ sơ giữ chỗ chưa xử lý xong —
            // cùng lý do đã bỏ chặn ở RegistrationPeriodController::process(): 1 hồ sơ giữ chỗ
            // approved-chưa-nhập-học có thể KHÔNG BAO GIỜ hết trạng thái này (sinh viên bỏ
            // không nhập học), chặn cứng ở đây sẽ khiến đợt không bao giờ xác nhận được. Cảnh
            // báo vẫn hiển thị ở FE để nhắc admin xử lý, không còn ngăn hành động.

            // Lock đúng tập registration còn 'submitted' TẠI THỜI ĐIỂM lock (1 query, không
            // tách bước) — registration nào đã bị xử lý/hủy bởi request khác trước khi lock
            // được cấp sẽ tự động không nằm trong tập này, không có chuyện "xác nhận âm thầm"
            // một registration đã cancelled.
            $registrations = Registration::with('student')
                ->where('registration_period_id', $periodId)
                ->whereIn('status', ['submitted'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Lock luôn tập DormReservation nguồn (nếu có Registration nào là nguồn giữ chỗ
            // tân sinh viên) trong CÙNG transaction, theo đúng thứ tự id tăng dần — nhất
            // quán lock order chung của hệ thống (RegistrationPeriod trước, rồi tới
            // DormReservation/Registration theo id).
            $sourceReservationIds = $registrations->pluck('source_dorm_reservation_id')->filter()->unique()->sort()->values();
            $sourceReservations = $sourceReservationIds->isEmpty()
                ? collect()
                : DormReservation::whereIn('id', $sourceReservationIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Minh chứng ưu tiên bị từ chối — không được nằm trong đề xuất duyệt, dù
            // auto_decision đang là 'approve' (1 query, không N+1 theo từng registration).
            $rejectedEvidenceRegIds = StudentPriority::whereIn('registration_id', $registrations->pluck('id'))
                ->where('status', 'rejected')
                ->distinct()
                ->pluck('registration_id');

            // Registration nguồn giữ chỗ KHÔNG tính vào approveProposalCount — suất của
            // chúng đã được DormReservation approved giữ từ trước (đếm ở
            // approved_dorm_reservations), không phải suất mới cần capacity thường cấp.
            //
            // Kiểm tra RIÊNG theo từng giới tính — giường tách riêng theo giới ở cấp Floor,
            // gộp chung 1 số sẽ để lọt trường hợp vượt suất 1 giới trong khi giới kia còn dư
            // (xem PriorityRankingService::rankPeriod()).
            $approveProposals = $registrations
                ->where('auto_decision', 'approve')
                ->reject(fn ($r) => $rejectedEvidenceRegIds->contains($r->id))
                ->reject(fn ($r) => $r->source_dorm_reservation_id !== null);

            foreach (['male', 'female'] as $gender) {
                $genderProposalCount = $approveProposals
                    ->filter(fn ($r) => strtolower($r->student->gender ?? '') === $gender)
                    ->count();
                if ($genderProposalCount === 0) {
                    continue;
                }
                // excludeReservationsWithRegistration: true — cùng lý do như process(): hồ sơ
                // giữ chỗ đã có Registration (nguồn source_dorm_reservation_id) bị loại khỏi
                // $genderProposalCount ở trên rồi (suất của họ không phải suất MỚI cần xin
                // thêm), nhưng nếu không loại luôn khỏi approved_dorm_reservations, hồ sơ giữ
                // chỗ NÀO cũng bị tính là "đã chiếm 1 giường" tại đây — kể cả người sắp bị từ
                // chối ngay trong chính lượt confirmBatch này (reservation của họ chỉ bị demote
                // 'waitlisted' SAU khi vòng lặp xác nhận chạy xong, không phải trước lúc kiểm
                // tra sức chứa) — khiến hệ thống tưởng hết suất, chặn nhầm cả đơn MỚI hợp lệ
                // (báo cáo 28/07: 1 đơn nữ mới hợp lệ bị chặn dù còn suất thật do 2 hồ sơ giữ
                // chỗ cũ đều bị tính "đã chiếm giường" dù 1 trong 2 sắp bị từ chối).
                $capacity = app(DormCapacityService::class)->summarizeForRegistrationPeriod($period, $genderProposalCount, gender: $gender, excludeReservationsWithRegistration: true);
                if (($capacity['capacity_exceeded'] ?? false) === true) {
                    $genderLabel = $gender === 'male' ? 'nam' : 'nữ';
                    return [
                        'ok'      => false,
                        'status'  => 422,
                        'payload' => [
                            'message'  => "Sức chứa KTX ({$genderLabel}) đã thay đổi. Hiện chỉ có thể duyệt {$capacity['available_approval_slots']} hồ sơ {$genderLabel} nhưng đang chọn {$genderProposalCount} hồ sơ. Vui lòng điều chỉnh lại.",
                            'capacity' => $capacity,
                        ],
                    ];
                }
            }

            $confirmed = 0;
            $skippedReview = 0;
            $skippedNull = 0;
            $skippedRejectedEvidence = 0;
            $toNotify = [];
            $bumpedReservationRegIds = [];

            foreach ($registrations as $reg) {
                if ($rejectedEvidenceRegIds->contains($reg->id)) {
                    // Minh chứng ưu tiên không hợp lệ — không được confirm batch/single dù
                    // auto_decision đề xuất gì. Ép thẳng sang rejected, không giữ nguyên
                    // submitted (tránh còn xuất hiện ở "Chờ xác nhận" cho đợt đã đóng).
                    $reg->status = 'rejected';
                    $reg->rejection_reason = 'Minh chứng ưu tiên không hợp lệ.';
                    $reg->auto_decision = 'reject';
                    $reg->auto_decision_reason = 'Minh chứng ưu tiên không hợp lệ.';
                    $reg->save();
                    $toNotify[] = $reg;
                    $skippedRejectedEvidence++;
                    continue;
                }

                if ($reg->auto_decision === 'approve') {
                    $reg->status = 'approved';
                    $reg->approved_at = now();
                    $reg->save();
                    $toNotify[] = $reg;
                    $confirmed++;

                    // Registration nguồn giữ chỗ — chuyển luôn DormReservation nguồn sang
                    // converted trong CÙNG transaction. DormReservation không còn approved
                    // (bất thường, ví dụ bị admin đổi tay ở nơi khác giữa lúc chờ xác nhận)
                    // thì throw để rollback TOÀN BỘ batch — không để 1 phần Registration đã
                    // approved còn phần DormReservation dở dang.
                    if ($reg->source_dorm_reservation_id) {
                        $sourceReservation = $sourceReservations->get($reg->source_dorm_reservation_id);
                        if (!$sourceReservation || $sourceReservation->status !== 'approved') {
                            throw new RuntimeException("CONFIRM_BATCH_SOURCE_RESERVATION_INVALID:{$reg->id}");
                        }
                        $sourceReservation->update([
                            'status' => 'converted',
                            'converted_registration_id' => $reg->id,
                        ]);
                    }
                } elseif ($reg->auto_decision === 'reject') {
                    $reg->status = 'rejected';
                    if ($reg->auto_decision_reason) {
                        $reg->rejection_reason = $this->studentFacingRejectionReason($reg->auto_decision_reason);
                    }
                    $reg->save();
                    $toNotify[] = $reg;
                    $confirmed++;

                    // Registration nguồn giữ chỗ thua điểm ưu tiên ở vòng xếp hạng chung —
                    // trả DormReservation nguồn về waitlisted (KHÔNG rejected hẳn, vẫn có thể
                    // đôn lại nếu còn suất trống) trong CÙNG transaction, cùng quy tắc "throw để
                    // rollback toàn batch nếu dữ liệu nguồn bất thường" như nhánh approve.
                    if ($reg->source_dorm_reservation_id) {
                        $sourceReservation = $sourceReservations->get($reg->source_dorm_reservation_id);
                        if (!$sourceReservation || $sourceReservation->status !== 'approved') {
                            throw new RuntimeException("CONFIRM_BATCH_SOURCE_RESERVATION_INVALID:{$reg->id}");
                        }
                        $sourceReservation->update([
                            'status'               => 'waitlisted',
                            'auto_decision'        => null,
                            'auto_decision_reason' => null,
                        ]);
                        $bumpedReservationRegIds[] = $reg->id;
                    }
                } elseif ($reg->auto_decision === 'review') {
                    $skippedReview++;
                } else {
                    $skippedNull++;
                }
            }

            $period->status = 'closed';
            $period->save();

            return [
                'ok'                       => true,
                'confirmed'                => $confirmed,
                'skippedReview'            => $skippedReview,
                'skippedNull'              => $skippedNull,
                'skippedRejectedEvidence'  => $skippedRejectedEvidence,
                'toNotify'                 => $toNotify,
                'bumpedReservationRegIds'  => $bumpedReservationRegIds,
            ];
        });
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'CONFIRM_BATCH_SOURCE_RESERVATION_INVALID:')) {
                $failedRegId = substr($exception->getMessage(), strlen('CONFIRM_BATCH_SOURCE_RESERVATION_INVALID:'));

                return response()->json([
                    'message' => "Hồ sơ giữ chỗ nguồn của đơn #{$failedRegId} không còn ở trạng thái approved. Toàn bộ thao tác xác nhận hàng loạt đã được hủy, vui lòng tải lại dữ liệu.",
                ], 422);
            }

            throw $exception;
        }

        if (!$result['ok']) {
            return response()->json($result['payload'], $result['status']);
        }

        // Gửi thông báo SAU khi transaction đã commit (vẫn queue:true như hành vi cũ).
        $bumpedRegIds = collect($result['bumpedReservationRegIds']);
        foreach ($result['toNotify'] as $reg) {
            if ($reg->student) {
                $this->notifyRegistrationDecision($reg, queue: true);

                if ($bumpedRegIds->contains($reg->id)) {
                    $this->notifier()->notifyStudent(
                        $reg->student,
                        'Hồ sơ giữ chỗ KTX chuyển sang danh sách chờ',
                        'Khi xếp hạng chung toàn đợt (gồm cả đăng ký thường), hồ sơ giữ chỗ KTX của bạn không đủ thứ hạng ưu tiên để giữ suất và đã chuyển sang danh sách chờ. Bạn vẫn có thể được đôn lại nếu còn suất trống — vui lòng theo dõi thông báo tiếp theo.',
                        'dorm_reservation_waitlisted',
                        $reg->source_dorm_reservation_id,
                        queue: true,
                    );
                }
            }
        }

        // Registration vừa chốt xong 'rejected' thật sự ở trên — đúng thời điểm an toàn để
        // xử lý hồ sơ giữ chỗ tân sinh viên hết hạn (nếu có) và đôn Registration bị từ chối
        // tương ứng lên duyệt, thay vì chỉ cron mới làm được (báo cáo 30/07). Idempotent —
        // gọi lại nhiều lần không đôn trùng, không xử lý trùng.
        $closedPeriod = RegistrationPeriod::find($periodId);
        if ($closedPeriod) {
            app(DormReservationExpiryService::class)->processExpiredReservationsAndPromote($closedPeriod);
        }

        return response()->json([
            'confirmed'                  => $result['confirmed'],
            'skipped_review'             => $result['skippedReview'],
            'skipped_null'               => $result['skippedNull'],
            'skipped_rejected_evidence'  => $result['skippedRejectedEvidence'],
        ]);
    }

    /**
     * Kiểm tra nhóm đối tượng của đợt đăng ký, thuần theo channel — không còn đọc
     * allow_admission_candidates/requires_student_code (2 cờ này giờ luôn true, chỉ còn
     * ý nghĩa cho luồng giữ chỗ tân sinh viên ở nơi khác, xem DormReservationController).
     *
     * Đợt chính ('main'): chỉ tân sinh viên (qua giữ chỗ) + sinh viên năm nhất đã có MSSV.
     * Sinh viên năm 2 trở lên ở tiếp phải qua Gia hạn (OccupancyExtensionController —
     * hoàn toàn tách biệt, không liên quan hàm này).
     * Quanh năm ('rolling'): mọi sinh viên đang học, vẫn qua đủ các check khác ở
     * eligibility() (tốt nghiệp/thôi học/blacklist/quá năm).
     *
     * @return array{reason_code: string, message: string}|null
     */
    private function registrationPeriodTargetError(RegistrationPeriod $period, Student $student): ?array
    {
        if ($period->channel !== 'main') {
            return null;
        }

        if ((int) ($student->current_year ?? 0) !== 1) {
            return [
                'reason_code' => 'not_first_year',
                'message'     => 'Đợt chính chỉ dành cho tân sinh viên và sinh viên năm nhất đã có MSSV.',
            ];
        }

        return null;
    }

    /**
     * Nguồn chân lý cho "trạng thái lưu trú" của 1 registration — dùng bởi index()
     * (trang Quản lý lưu trú) và tái sử dụng ở nơi khác (vd. StudentFaceSearchController)
     * để tránh lệch logic khi xác định ai đang được tính là "có lưu trú".
     */
    public function mapOccupancyStatus(?Occupancy $occupancy): ?string
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
