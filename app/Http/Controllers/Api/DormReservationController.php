<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdmissionCandidate;
use App\Models\Bed;
use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\ReservationPriority;
use App\Models\StudentPriority;
use App\Models\StudentPriorityEvidence;
use App\Services\PriorityRankingService;
use App\Services\StudentNotificationService;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DormReservationController extends Controller
{
    private const ACTIVE_RESERVATION_STATUSES = ['submitted', 'approved', 'waitlisted'];

    // =========================================================
    // Public routes
    // =========================================================

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admission_code' => ['required', 'string'],
        ]);

        $candidate = AdmissionCandidate::where('admission_code', $data['admission_code'])->first();

        if (!$candidate) {
            return response()->json([
                'verification_status' => 'not_found',
                'message'             => 'Không tìm thấy hồ sơ trúng tuyển phù hợp.',
            ]);
        }

        $activePeriod = $this->findActiveAdmissionPeriod();
        $existingReservation = $this->findBlockingReservation($candidate->id, $activePeriod?->id);

        if ($candidate->status === 'cancelled') {
            return response()->json([
                'verification_status' => 'cancelled',
                'message'             => 'Hồ sơ trúng tuyển đã bị hủy hoặc không còn hiệu lực. Vui lòng liên hệ nhà trường để được hỗ trợ.',
                'full_name'           => $candidate->full_name,
                'major_name'          => $candidate->major_name,
                'course_year'         => $candidate->course_year,
                'gender'              => $candidate->gender,
            ]);
        }

        if ($existingReservation?->status === 'converted') {
            return response()->json($this->verificationPayload(
                $candidate,
                $candidate->status === 'enrolled' ? 'enrolled' : 'admitted',
                'Bạn đã hoàn tất đăng ký chính thức. Vui lòng đăng nhập bằng MSSV để tiếp tục.',
                $existingReservation,
            ));
        }

        if ($candidate->status === 'enrolled') {
            return response()->json([
                'verification_status' => 'enrolled',
                'message'             => 'Bạn đã chính thức là sinh viên của trường. Vui lòng đăng ký ký túc xá bằng MSSV.',
                'full_name'           => $candidate->full_name,
                'major_name'          => $candidate->major_name,
                'course_year'         => $candidate->course_year,
                'gender'              => $candidate->gender,
            ]);
        }

        if ($candidate->status !== 'admitted') {
            return response()->json([
                'verification_status' => 'not_found',
                'message'             => 'Không tìm thấy hồ sơ trúng tuyển phù hợp.',
            ]);
        }

        if ($existingReservation) {
            return response()->json($this->verificationPayload(
                $candidate,
                'admitted',
                'Bạn đã có hồ sơ giữ chỗ đang hoạt động trong đợt này.',
                $existingReservation,
            ));
        }

        return response()->json([
            'verification_status' => 'admitted',
            'message'             => 'Đã xác minh hồ sơ trúng tuyển.',
            'full_name'           => $candidate->full_name,
            'major_name'          => $candidate->major_name,
            'course_year'         => $candidate->course_year,
            'gender'              => $candidate->gender,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admission_code'         => ['required', 'string'],
            'registration_period_id' => ['required', 'integer', 'exists:registration_periods,id'],
            'email'                  => ['nullable', 'email', 'max:191'],
            'priority_note'          => ['nullable', 'string', 'max:1000'],
            'father_name'            => ['nullable', 'string', 'max:191'],
            'father_birth_year'      => ['nullable', 'string', 'max:10'],
            'father_job'             => ['nullable', 'string', 'max:191'],
            'father_phone'           => ['nullable', 'string', 'max:20'],
            'mother_name'            => ['nullable', 'string', 'max:191'],
            'mother_birth_year'      => ['nullable', 'string', 'max:10'],
            'mother_job'             => ['nullable', 'string', 'max:191'],
            'mother_phone'           => ['nullable', 'string', 'max:20'],
            'parent_address'         => ['nullable', 'string'],
            'commitment_confirm'     => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($data) {
            // Lock candidate để hai request submit gần như đồng thời không thể cùng tạo hồ sơ.
            $candidate = AdmissionCandidate::where('admission_code', $data['admission_code'])
                ->lockForUpdate()
                ->first();

            if (!$candidate || $candidate->status !== 'admitted') {
                return response()->json(['message' => 'Mã hồ sơ trúng tuyển không hợp lệ hoặc không ở trạng thái trúng tuyển.'], 422);
            }

            // Nguồn sự thật duy nhất: đợt active đang mở cho tân sinh viên.
            $period = $this->findActiveAdmissionPeriod(lock: true);

            if (!$period || (int) $data['registration_period_id'] !== (int) $period->id) {
                return response()->json(['message' => 'Đợt đăng ký tân sinh viên hiện không hợp lệ hoặc đã đóng. Vui lòng tải lại trang và thử lại.'], 422);
            }

            $existingBlocking = $this->findBlockingReservation($candidate->id, $period->id, lock: true);

            if ($existingBlocking) {
                return response()->json([
                    'message' => $existingBlocking->status === 'converted'
                        ? 'Bạn đã hoàn tất đăng ký chính thức. Vui lòng đăng nhập bằng MSSV để tiếp tục.'
                        : 'Bạn đã có hồ sơ giữ chỗ đang hoạt động trong đợt này.',
                ], 422);
            }

            // Cập nhật thông tin liên hệ. Email LUÔN ghi đè bằng email thí sinh vừa tự nhập
            // (kênh liên lạc cá nhân cho giai đoạn trước nhập học — thông báo duyệt/từ chối/
            // chờ hồ sơ giữ chỗ qua notifyCandidate()) — không giữ điều kiện "chỉ ghi nếu
            // candidate chưa có email" như trước, vì candidate seed sẵn email (dữ liệu trường
            // gửi) sẽ luôn khiến email tự nhập bị bỏ qua, thông báo gửi sai chỗ.
            $updateCandidate = [];
            if (!empty($data['email'])) {
                $updateCandidate['email'] = $data['email'];
            }
            if ($updateCandidate) {
                $candidate->update($updateCandidate);
            }

            // Tạo reservation
            $reservation = DormReservation::create([
                'admission_candidate_id' => $candidate->id,
                'registration_period_id' => $period->id,
                'reservation_code'       => $this->generateReservationCode(),
                'status'                 => 'submitted',
                'priority_note'          => $data['priority_note'] ?? null,
                'father_name'            => $data['father_name'] ?? null,
                'father_birth_year'      => $data['father_birth_year'] ?? null,
                'father_job'             => $data['father_job'] ?? null,
                'father_phone'           => $data['father_phone'] ?? null,
                'mother_name'            => $data['mother_name'] ?? null,
                'mother_birth_year'      => $data['mother_birth_year'] ?? null,
                'mother_job'             => $data['mother_job'] ?? null,
                'mother_phone'           => $data['mother_phone'] ?? null,
                'parent_address'         => $data['parent_address'] ?? null,
                'commitment_confirm'     => $data['commitment_confirm'] ?? false,
                'submitted_at'           => now(),
            ]);

            return response()->json([
                'message'     => 'Đã gửi hồ sơ giữ chỗ thành công.',
                'reservation' => [
                    'id'               => $reservation->id,
                    'reservation_code' => $reservation->reservation_code,
                    'status'           => $reservation->status,
                ],
            ], 201);
        });
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_code' => ['required', 'string', 'max:50'],
        ]);

        $reservationCode = Str::upper(trim($data['reservation_code']));

        $reservation = DormReservation::with([
                'period',
                'candidate:id,full_name,major_name,cccd,email,phone',
            ])
            ->where('reservation_code', $reservationCode)
            ->first();

        if (!$reservation) {
            return response()->json([
                'message' => 'Không tìm thấy hồ sơ với mã này, vui lòng kiểm tra lại.',
            ], 404);
        }

        return response()->json([
            'message'     => 'Đã tìm thấy hồ sơ giữ chỗ.',
            'reservation' => $this->reservationProgressPayload($reservation),
        ]);
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

    private function findActiveReservation(int $candidateId, int $periodId, bool $lock = false): ?DormReservation
    {
        $query = DormReservation::with('period')
            ->where('admission_candidate_id', $candidateId)
            ->where('registration_period_id', $periodId)
            ->whereIn('status', self::ACTIVE_RESERVATION_STATUSES)
            ->latest('submitted_at')
            ->latest('created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function findConvertedReservation(int $candidateId, bool $lock = false): ?DormReservation
    {
        $query = DormReservation::with('period')
            ->where('admission_candidate_id', $candidateId)
            ->where('status', 'converted')
            ->latest('updated_at')
            ->latest('created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function findBlockingReservation(int $candidateId, ?int $periodId, bool $lock = false): ?DormReservation
    {
        $converted = $this->findConvertedReservation($candidateId, $lock);
        if ($converted) {
            return $converted;
        }

        if (!$periodId) {
            return null;
        }

        return $this->findActiveReservation($candidateId, $periodId, $lock);
    }

    private function verificationPayload(
        AdmissionCandidate $candidate,
        string $verificationStatus,
        string $message,
        ?DormReservation $reservation = null,
    ): array {
        $payload = [
            'verification_status' => $verificationStatus,
            'message'             => $message,
            'full_name'           => $candidate->full_name,
            'major_name'          => $candidate->major_name,
            'course_year'         => $candidate->course_year,
            'gender'              => $candidate->gender,
        ];

        if ($reservation) {
            $payload['existing_reservation'] = [
                'reservation_code' => $reservation->reservation_code,
                'status'           => $reservation->status,
                'submitted_at'     => $this->reservationSubmittedAt($reservation),
                'period_name'      => $reservation->period?->name,
            ];
        }

        return $payload;
    }

    private function reservationProgressPayload(DormReservation $reservation): array
    {
        $payload = [
            'reservation_code' => $reservation->reservation_code,
            'status'           => $reservation->status,
            'submitted_at'     => $this->reservationSubmittedAt($reservation),
            'approved_at'      => $reservation->approved_at?->toISOString(),
            'period_name'      => $reservation->period?->name,
        ];

        if ($reservation->candidate) {
            $payload['candidate'] = [
                'full_name'   => $reservation->candidate->full_name,
                'major_name'  => $reservation->candidate->major_name,
                'masked_cccd' => $this->maskIdentifier($reservation->candidate->cccd),
                'masked_email' => $this->maskEmail($reservation->candidate->email),
                'masked_phone' => $this->maskPhone($reservation->candidate->phone),
            ];
        }

        if ($reservation->status === 'rejected' && $reservation->rejection_reason) {
            $payload['rejection_reason'] = $reservation->rejection_reason;
        }

        if ($reservation->status === 'cancelled') {
            $payload['cancellation_reason'] = $reservation->cancellation_reason;
        }

        return $payload;
    }

    private function reservationSubmittedAt(DormReservation $reservation): ?string
    {
        return ($reservation->submitted_at ?? $reservation->created_at)?->toISOString();
    }

    private function maskIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $last = mb_substr($value, -4);
        return str_repeat('*', max(0, mb_strlen($value) - mb_strlen($last))) . $last;
    }

    private function maskEmail(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || !str_contains($value, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);
        $prefix = mb_substr($local, 0, min(4, mb_strlen($local)));

        return $prefix . '***@' . $domain;
    }

    private function maskPhone(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) <= 4) {
            return $this->maskIdentifier($value);
        }

        return mb_substr($value, 0, 2) . str_repeat('*', max(0, mb_strlen($value) - 4)) . mb_substr($value, -2);
    }

    // =========================================================
    // Admin routes
    // =========================================================

    public function index(Request $request): JsonResponse
    {
        $query = DormReservation::with(['candidate', 'period'])
            ->orderByDesc('created_at');

        if ($s = $request->input('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('reservation_code', 'like', "%{$s}%")
                  ->orWhere('student_code', 'like', "%{$s}%")
                  ->orWhereHas('candidate', function ($cq) use ($s) {
                      $cq->where('admission_code', 'like', "%{$s}%")
                         ->orWhere('full_name', 'like', "%{$s}%")
                         ->orWhere('cccd', 'like', "%{$s}%")
                         ->orWhere('phone', 'like', "%{$s}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($periodId = $request->input('registration_period_id')) {
            $query->where('registration_period_id', $periodId);
        }

        return response()->json($query->paginate(20));
    }

    public function show(int $id): JsonResponse
    {
        $reservation = DormReservation::with([
            'candidate.student',
            'period',
            'convertedRegistration',
            'reservationPriorities.criteria',
            'reservationPriorities.evidences',
        ])->findOrFail($id);

        return response()->json($reservation);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if (!in_array($reservation->status, ['submitted', 'waitlisted'], true)) {
            return response()->json(['message' => 'Chỉ duyệt được hồ sơ ở trạng thái đã nộp hoặc đang chờ.'], 422);
        }

        $reservation->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'admin_note'  => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đã được duyệt',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn đã được duyệt. Vui lòng theo dõi email/thông báo để hoàn tất thủ tục nhập học và đăng ký lưu trú chính thức.',
        );

        return response()->json(['message' => 'Đã duyệt hồ sơ giữ chỗ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if (!in_array($reservation->status, ['submitted', 'waitlisted', 'approved'], true)) {
            return response()->json(['message' => 'Không thể từ chối hồ sơ ở trạng thái hiện tại.'], 422);
        }

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $reservation->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
            'admin_note'       => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX bị từ chối',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị từ chối. Lý do: ' . $data['rejection_reason'],
        );

        return response()->json(['message' => 'Đã từ chối hồ sơ giữ chỗ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function waitlist(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if ($reservation->status !== 'submitted') {
            return response()->json(['message' => 'Chỉ chuyển được hồ sơ đã nộp vào danh sách chờ.'], 422);
        }

        $reservation->update([
            'status'     => 'waitlisted',
            'admin_note' => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đang chờ xét duyệt',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn hiện đang ở danh sách chờ do số lượng chỗ có hạn. Vui lòng theo dõi thông báo tiếp theo.',
        );

        return response()->json(['message' => 'Đã chuyển vào danh sách chờ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if (in_array($reservation->status, ['converted', 'cancelled'], true)) {
            return response()->json(['message' => 'Hồ sơ đã được chuyển đổi hoặc đã huỷ, không thể huỷ lại.'], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'admin_note' => ['nullable', 'string'],
        ]);

        $reason = trim($data['reason']);
        if ($reason === '') {
            return response()->json([
                'message' => 'Vui lòng nhập lý do hủy giữ chỗ.',
                'errors' => [
                    'reason' => ['Vui lòng nhập lý do hủy giữ chỗ.'],
                ],
            ], 422);
        }

        $reservation->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $reason,
            'admin_note'          => $data['admin_note'] ?? $reservation->admin_note,
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đã bị huỷ',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị huỷ.',
        );

        return response()->json(['message' => 'Đã huỷ hồ sơ giữ chỗ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::findOrFail($id);

        $data = $request->validate([
            'admin_note' => ['required', 'string', 'max:2000'],
        ]);

        $reservation->update(['admin_note' => $data['admin_note']]);

        return response()->json(['message' => 'Đã cập nhật ghi chú.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function convertToRegistration(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'registration_period_id' => ['nullable', 'integer', 'exists:registration_periods,id'],
        ]);

        return DB::transaction(function () use ($id, $data) {
            $reservation = DormReservation::with('candidate')->findOrFail($id);

            if ($reservation->status === 'converted') {
                return response()->json(['message' => 'Hồ sơ này đã được chuyển đổi thành đơn KTX rồi.'], 422);
            }

            if (!in_array($reservation->status, ['submitted', 'approved', 'waitlisted'], true)) {
                return response()->json(['message' => 'Chỉ chuyển đổi được hồ sơ ở trạng thái đã nộp, đã duyệt hoặc đang chờ.'], 422);
            }

            $candidate = $reservation->candidate;

            if ($candidate->status !== 'enrolled' || !$candidate->student_id) {
                return response()->json(['message' => 'Thí sinh chưa được chuyển thành sinh viên chính thức. Vui lòng xác nhận nhập học trước.'], 422);
            }

            // Xác định period
            $periodId = $data['registration_period_id'] ?? $reservation->registration_period_id;

            if (!$periodId) {
                return response()->json(['message' => 'Vui lòng cung cấp đợt đăng ký để chuyển đổi hồ sơ.'], 422);
            }

            $period = RegistrationPeriod::findOrFail($periodId);
            $studentId = $candidate->student_id;

            // Kiểm tra không tạo trùng registration cho cùng student + period
            $existingReg = Registration::where('student_id', $studentId)
                ->where('registration_period_id', $periodId)
                ->whereNotIn('status', ['rejected'])
                ->first();

            if ($existingReg) {
                return response()->json([
                    'message'         => 'Sinh viên đã có đơn đăng ký KTX trong đợt này.',
                    'registration_id' => $existingReg->id,
                ], 422);
            }

            // Xác định status registration
            $regStatus = ($reservation->status === 'approved') ? 'approved' : 'submitted';
            $approvedAt = ($regStatus === 'approved') ? now() : null;


            $registration = Registration::create([
                'student_id'             => $studentId,
                'registration_period_id' => $periodId,
                'semester'               => $period->semester,
                'school_year'            => $period->school_year,
                'stay_from_date'         => $period->stay_start_date?->format('Y-m-d'),
                'stay_to_date'           => $period->stay_end_date?->format('Y-m-d'),
                'status'                 => $regStatus,
                'registration_type'      => 'new',
                'approved_at'            => $approvedAt,
                'note'                   => "Chuyển từ hồ sơ giữ chỗ #{$reservation->reservation_code}",
                'commitment_confirm'     => true,
                'avatar_url'             => $reservation->avatar_url,
                'cccd_front_url'         => $reservation->cccd_front_url,
                'cccd_back_url'          => $reservation->cccd_back_url,
                'father_name'            => $reservation->father_name,
                'father_birth_year'      => $reservation->father_birth_year,
                'father_job'             => $reservation->father_job,
                'father_phone'           => $reservation->father_phone,
                'mother_name'            => $reservation->mother_name,
                'mother_birth_year'      => $reservation->mother_birth_year,
                'mother_job'             => $reservation->mother_job,
                'mother_phone'           => $reservation->mother_phone,
                'parent_address'         => $reservation->parent_address,
                'top_priority_tier'      => $reservation->top_priority_tier,
                'total_priority_score'   => $reservation->total_priority_score,
            ]);

            // Copy tiêu chí ưu tiên sang registration
            $this->copyPrioritiesToRegistration($reservation, $candidate->student_id, $registration->id);

            // Cập nhật reservation
            $reservation->update([
                'status'                  => 'converted',
                'converted_registration_id' => $registration->id,
            ]);

            // Hồ sơ giữ chỗ đã duyệt sẵn -> đơn nội trú tạo ra cũng approved luôn,
            // sinh viên (đã có tài khoản) cần được báo để chờ phân phòng.
            if ($regStatus === 'approved') {
                $student = Student::find($studentId);
                if ($student) {
                    app(StudentNotificationService::class)->notifyStudent(
                        $student,
                        'Đơn đăng ký nội trú đã được duyệt',
                        'Đơn đăng ký nội trú KTX của bạn đã được duyệt. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
                        'registration_approved',
                        $registration->id,
                    );
                }
            }

            return response()->json([
                'message'         => 'Đã chuyển đổi thành đơn KTX chính thức thành công.',
                'registration_id' => $registration->id,
                'registration'    => $registration,
                'reservation'     => $reservation->fresh(),
            ]);
        });
    }

    // =========================================================
    // Admin — ranking & batch convert
    // =========================================================

    /**
     * Xếp hạng tất cả hồ sơ submitted/waitlisted theo tiêu chí ưu tiên.
     * POST /admin/dorm-reservations/rank
     */
    public function rankReservations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_period_id' => ['required', 'integer', 'exists:registration_periods,id'],
        ]);

        $periodId = $data['registration_period_id'];

        $pendingCount = ReservationPriority::whereHas(
            'dormReservation',
            fn ($q) => $q->where('registration_period_id', $periodId)
        )->where('status', 'pending')->count();

        if ($pendingCount > 0) {
            return response()->json([
                'message'                => "Còn {$pendingCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh tất cả minh chứng trước khi xếp hạng.",
                'pending_priority_count' => $pendingCount,
            ], 422);
        }

        $totalAvailable = Bed::where('status', 'active')->count();
        $occupiedCount  = Occupancy::occupiedBedsQuery()->pluck('bed_id')->unique()->count();
        $freeBeds       = max(0, $totalAvailable - $occupiedCount);

        $ranker = new PriorityRankingService();
        $result = $ranker->rankReservationPeriod($periodId, $freeBeds);

        foreach ($result['approved'] as $reservation) {
            $reservation->update(['status' => 'approved', 'approved_at' => now()]);
            $this->notifyCandidate(
                $reservation->candidate,
                'Hồ sơ giữ chỗ KTX đã được duyệt',
                'Hồ sơ đăng ký giữ chỗ KTX của bạn đã được duyệt. Vui lòng theo dõi email/thông báo để hoàn tất thủ tục nhập học và đăng ký lưu trú chính thức.',
            );
        }
        foreach ($result['waitlist'] as $reservation) {
            $reservation->update(['status' => 'waitlisted']);
            $this->notifyCandidate(
                $reservation->candidate,
                'Hồ sơ giữ chỗ KTX đang chờ xét duyệt',
                'Hồ sơ đăng ký giữ chỗ KTX của bạn hiện đang ở danh sách chờ do số lượng chỗ có hạn. Vui lòng theo dõi thông báo tiếp theo.',
            );
        }

        return response()->json([
            'message'   => 'Đã xếp hạng xong.',
            'free_beds' => $freeBeds,
            'approved'  => $result['approved']->count(),
            'waitlist'  => $result['waitlist']->count(),
        ]);
    }

    /**
     * Chuyển hàng loạt hồ sơ approved + candidate đã nhập học thành đơn KTX.
     * POST /admin/dorm-reservations/batch-convert
     */
    public function batchConvert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_period_id' => ['required', 'integer', 'exists:registration_periods,id'],
        ]);

        $periodId = $data['registration_period_id'];
        $period   = RegistrationPeriod::findOrFail($periodId);

        $reservations = DormReservation::where('registration_period_id', $periodId)
            ->where('status', 'approved')
            ->with('candidate')
            ->get();

        $converted = 0;
        $skipped   = 0;
        $errors    = [];

        foreach ($reservations as $reservation) {
            $candidate = $reservation->candidate;

            if (!$candidate || $candidate->status !== 'enrolled' || !$candidate->student_id) {
                $skipped++;
                continue;
            }

            $studentId = $candidate->student_id;

            $existing = Registration::where('student_id', $studentId)
                ->where('registration_period_id', $periodId)
                ->whereNotIn('status', ['rejected'])
                ->exists();

            if ($existing) {
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($reservation, $period, $periodId, $studentId, &$converted) {
                    $registration = Registration::create([
                        'student_id'             => $studentId,
                        'registration_period_id' => $periodId,
                        'semester'               => $period->semester,
                        'school_year'            => $period->school_year,
                        'stay_from_date'         => $period->stay_start_date?->format('Y-m-d'),
                        'stay_to_date'           => $period->stay_end_date?->format('Y-m-d'),
                        'status'                 => 'approved',
                        'registration_type'      => 'new',
                        'approved_at'            => now(),
                        'note'                   => "Chuyển từ hồ sơ giữ chỗ #{$reservation->reservation_code}",
                        'commitment_confirm'      => true,
                        'avatar_url'             => $reservation->avatar_url,
                        'cccd_front_url'         => $reservation->cccd_front_url,
                        'cccd_back_url'          => $reservation->cccd_back_url,
                        'father_name'            => $reservation->father_name,
                        'father_birth_year'      => $reservation->father_birth_year,
                        'father_job'             => $reservation->father_job,
                        'father_phone'           => $reservation->father_phone,
                        'mother_name'            => $reservation->mother_name,
                        'mother_birth_year'      => $reservation->mother_birth_year,
                        'mother_job'             => $reservation->mother_job,
                        'mother_phone'           => $reservation->mother_phone,
                        'parent_address'         => $reservation->parent_address,
                        'top_priority_tier'      => $reservation->top_priority_tier,
                        'total_priority_score'   => $reservation->total_priority_score,
                    ]);

                    $this->copyPrioritiesToRegistration($reservation, $studentId, $registration->id);

                    $reservation->update([
                        'status'                    => 'converted',
                        'converted_registration_id' => $registration->id,
                    ]);

                    $student = Student::find($studentId);
                    if ($student) {
                        // Đang chạy trong vòng lặp duyệt hàng loạt đặt chỗ theo đợt — queue
                        // để không chặn request chờ gửi email lần lượt.
                        app(StudentNotificationService::class)->notifyStudent(
                            $student,
                            'Đơn đăng ký nội trú đã được duyệt',
                            'Đơn đăng ký nội trú KTX của bạn đã được duyệt. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
                            'registration_approved',
                            $registration->id,
                            queue: true,
                        );
                    }

                    $converted++;
                });
            } catch (\Throwable $e) {
                $errors[] = [
                    'reservation_id'   => $reservation->id,
                    'reservation_code' => $reservation->reservation_code,
                    'error'            => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message'   => "Đã chuyển đổi {$converted} hồ sơ.",
            'converted' => $converted,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ]);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function notifyCandidate(?AdmissionCandidate $candidate, string $title, string $content): void
    {
        if (!$candidate) {
            return;
        }

        app(StudentNotificationService::class)->notifyEmailOnly($candidate->email, $candidate->full_name, $title, $content);
    }

    private function generateReservationCode(): string
    {
        do {
            $code = 'DORM-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (DormReservation::where('reservation_code', $code)->exists());

        return $code;
    }

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reservation_code' => ['required', 'string'],
            'type'             => ['required', \Illuminate\Validation\Rule::in(['avatar', 'cccd_front', 'cccd_back'])],
            'file'             => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $reservation = DormReservation::where('id', $id)
            ->where('reservation_code', $request->input('reservation_code'))
            ->firstOrFail();

        $path = $request->file('file')->store('reservation-documents', 'public');
        $url  = '/api/storage/' . $path;

        $column = match ($request->input('type')) {
            'avatar'     => 'avatar_url',
            'cccd_front' => 'cccd_front_url',
            'cccd_back'  => 'cccd_back_url',
        };

        $reservation->update([$column => $url]);

        return response()->json(['message' => 'Đã tải lên thành công.']);
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
