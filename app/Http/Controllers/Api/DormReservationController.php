<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\AdmissionCandidate;
use App\Models\Bed;
use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\ReservationPriority;
use App\Models\StudentPriority;
use App\Models\StudentPriorityEvidence;
use App\Services\DormReservationConversionService;
use App\Services\DormWaitlistPromotionService;
use App\Services\DormCapacityService;
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

    /**
     * Đường dẫn minh chứng ưu tiên lưu trong DB là đường dẫn TƯƠNG ĐỐI (VD
     * "/api/storage/reservation-evidences/xxx.jpg") — dùng thẳng làm <img src> ở FE sẽ bị
     * trình duyệt tự ghép với domain của chính FE (Vercel), không phải domain BE (Railway),
     * vỡ ảnh khi FE/BE khác domain nhau (báo cáo 28/07: ảnh minh chứng bên "Đơn đăng ký" hiện
     * đúng vì RegistrationController có bước này, còn "Hồ sơ giữ chỗ tân sinh viên" trả thẳng
     * model ra JSON, thiếu bước build URL đầy đủ). Copy cùng logic với RegistrationController.
     */
    private function getImageUrl($path)
    {
        if (empty($path)) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));

        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        if ($isProduction) {
            return url('/api/storage/' . $cleanPath);
        }

        return url('/storage/' . $cleanPath);
    }

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

        if (!$activePeriod) {
            return response()->json([
                'verification_status' => 'period_closed',
                'message'             => 'Đợt đăng ký giữ chỗ KTX đã kết thúc.',
                'full_name'           => $candidate->full_name,
                'major_name'          => $candidate->major_name,
                'course_year'         => $candidate->course_year,
                'gender'              => $candidate->gender,
            ]);
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

            if (!$period) {
                return response()->json(['message' => 'Đợt đăng ký giữ chỗ KTX đã kết thúc.'], 422);
            }

            if ((int) $data['registration_period_id'] !== (int) $period->id) {
                return response()->json(['message' => 'Đợt đăng ký tân sinh viên hiện không hợp lệ. Vui lòng tải lại trang và thử lại.'], 422);
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
                'candidate:id,full_name,major_name,cccd,email,phone,status,student_id',
                'convertedRegistration',
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

    /** Message chung cho MỌI lý do xác minh thất bại — không tiết lộ hồ sơ có tồn tại hay
     *  email đúng/sai (chống dò reservation_code + email theo từng phần riêng biệt). */
    private const CANCEL_VERIFICATION_FAILED_MESSAGE = 'Không thể xác minh thông tin hồ sơ hoặc hồ sơ không đủ điều kiện hủy.';

    /**
     * Tự hủy nhu cầu ở KTX trước deadline — reservation_code KHÔNG còn là bằng chứng sở
     * hữu duy nhất (dò được bằng brute-force dù có throttle) — bắt buộc thêm email đã
     * đăng ký với hồ sơ làm yếu tố thứ 2, so khớp tuyệt đối (chuẩn hóa lowercase+trim) với
     * admission_candidates.email. Không có cơ chế token/OTP dùng chung sẵn có trong dự án
     * để tái dùng (xem rà soát) nên chọn phương án B theo yêu cầu. Dùng chung cho cả thí
     * sinh chưa có tài khoản lẫn sinh viên đã enrolled (chưa làm route auth:sanctum riêng
     * — xem báo cáo cuối, quyết định hoãn vì scope lớn, không bắt buộc theo yêu cầu).
     */
    public function cancelSelf(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_code' => ['required', 'string', 'max:50'],
            'email'            => ['required', 'string', 'email', 'max:255'],
            'reason'           => ['required', 'string', 'max:1000'],
        ]);

        $reservationCode = Str::upper(trim($data['reservation_code']));
        $email = Str::lower(trim($data['email']));

        $reservation = DormReservation::with('candidate')
            ->where('reservation_code', $reservationCode)
            ->first();

        // Cùng 1 message cho "không tìm thấy" lẫn "email không khớp" — tránh lộ việc mã
        // hồ sơ có tồn tại hay không qua nội dung phản hồi.
        if (!$reservation || !$reservation->candidate || !$reservation->candidate->email
            || Str::lower(trim($reservation->candidate->email)) !== $email
        ) {
            return response()->json([
                'message' => self::CANCEL_VERIFICATION_FAILED_MESSAGE,
            ], 422);
        }

        $reason = trim($data['reason']);
        if ($reason === '') {
            return response()->json([
                'message' => 'Vui lòng nhập lý do hủy.',
                'errors'  => ['reason' => ['Vui lòng nhập lý do hủy.']],
            ], 422);
        }

        // 'approved' (đã duyệt giữ chỗ, chưa nhập học/convert) và 'converted' (đã có
        // Registration) đều cho tự hủy — DormReservationCancellationService::cancel() tự
        // phân biệt 2 trường hợp và áp đúng điều kiện chặn riêng (hạn, đã bị admin xác
        // nhận hay chưa...).
        if (!in_array($reservation->status, ['approved', 'converted'], true)) {
            return response()->json([
                'message' => 'Hồ sơ giữ chỗ không còn hỗ trợ hủy trực tuyến. Nếu hồ sơ không được duyệt, Ban quản lý KTX sẽ phản hồi bằng trạng thái Không được duyệt.',
                'code'    => 'not_cancellable',
            ], 422);
        }

        $result = app(\App\Services\DormReservationCancellationService::class)->cancel($reservation->id, $reason, 'candidate');

        if (!$result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'code'    => $result['code'],
            ], 422);
        }

        $promotionOutcome = null;
        $releasedGender = strtolower($reservation->candidate?->gender ?? '');
        if ($result['releasedApprovedSlot'] && $result['periodId'] && in_array($releasedGender, ['male', 'female'], true)) {
            $promotionOutcome = app(DormWaitlistPromotionService::class)->promoteOne($result['periodId'], $releasedGender);
        }

        // Gửi thông báo SAU KHI cả 2 transaction (hủy + đôn waitlist) đã commit — không
        // gửi mail trong transaction để tránh giữ lock lâu / rollback vẫn lỡ gửi mail.
        $this->notifyCandidate(
            $result['reservation']->candidate,
            'Đơn đăng ký nội trú KTX đã được hủy',
            'Đơn đăng ký nội trú của bạn đã được hủy trước thời hạn.',
        );

        if ($promotionOutcome && $promotionOutcome['promoted']) {
            $promotedReservation = $promotionOutcome['reservation'];
            $promotedCandidate   = $promotedReservation->candidate;

            if ($promotionOutcome['converted']) {
                $this->notifyCandidate(
                    $promotedCandidate,
                    'Đơn đăng ký nội trú KTX đã được tạo',
                    'Bạn đã được chuyển từ danh sách chờ và đơn đăng ký nội trú KTX đã được tạo thành công.',
                );
            } elseif ($promotedCandidate?->status !== 'enrolled') {
                $this->notifyCandidate(
                    $promotedCandidate,
                    'Hồ sơ giữ chỗ KTX đã được duyệt',
                    'Bạn đã được chuyển từ danh sách chờ sang trạng thái được duyệt giữ chỗ KTX. Vui lòng hoàn tất thủ tục nhập học theo hướng dẫn.',
                );
            }
            // Đã enrolled nhưng convert() thất bại (trùng/race) — KHÔNG gửi "hoàn tất nhập
            // học" (sai, vì đã enrolled) và KHÔNG khẳng định đã tạo Registration. Đã log
            // warning ở DormWaitlistPromotionService; admin xử lý thủ công.
        }

        return response()->json([
            'message'           => 'Đã hủy thành công.',
            'reservation'       => $this->reservationProgressPayload($result['reservation']->fresh(['candidate', 'period', 'convertedRegistration'])),
            'promoted_waitlist' => (bool) ($promotionOutcome['promoted'] ?? false),
        ]);
    }

    private function findActiveAdmissionPeriod(bool $lock = false): ?RegistrationPeriod
    {
        $query = RegistrationPeriod::where('status', 'active')
            ->where('allow_admission_candidates', true)
            ->orderByDesc('created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        // Chặn nộp mới sau hạn dự kiến (end_date lúc 17:00) ngay cả khi status DB chưa kịp
        // đồng bộ sang 'closed' (syncPeriodStatuses() chỉ chạy khi có ai gọi GET
        // /registration-periods, không đảm bảo chạy trước lúc store()/verify() được gọi).
        return $query->get()->first(function (RegistrationPeriod $period) {
            $deadline = $period->admissionDeadline();
            return $deadline === null || now()->lessThanOrEqualTo($deadline);
        });
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
                'period_end_date'  => $reservation->period?->end_date?->toDateString(),
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
            'period_end_date'  => $reservation->period?->end_date?->toDateString(),
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

        if ($reservation->status === 'expired' && $reservation->expiration_reason) {
            $payload['expiration_reason'] = $reservation->expiration_reason;
        }

        // reservation.status vẫn giữ 'converted' sau khi Registration bị hủy (bảo toàn
        // lịch sử — xem DormReservationCancellationService) — phải lộ trạng thái
        // Registration ra payload để FE biết đơn thật sự đã bị hủy, không hiển thị nhầm
        // "đã chuyển thành đơn chính thức" như bình thường.
        if ($reservation->status === 'converted' && $reservation->convertedRegistration) {
            $registration = $reservation->convertedRegistration;
            $payload['registration_status'] = $registration->status;

            if ($registration->status === 'cancelled') {
                $payload['registration_cancelled_at']        = $registration->cancelled_at?->toISOString();
                $payload['registration_cancellation_reason'] = $registration->cancellation_reason;
            }
        }

        $payload['can_cancel'] = $this->canCancelReservation($reservation);

        return $payload;
    }

    /** Đồng bộ đúng điều kiện với DormReservationCancellationService::cancel() — 2 giai đoạn
     *  được tự hủy: (1) đã duyệt giữ chỗ nhưng CHƯA nhập học/convert, (2) đã convert thành
     *  Registration nhưng Registration đó CHƯA được admin "Xác nhận" (còn 'submitted'). Ngay
     *  khi admin xác nhận xong thì KHÔNG cho tự hủy trực tuyến nữa. */
    private function canCancelReservation(DormReservation $reservation): bool
    {
        $deadline = $reservation->period?->admissionDeadline();
        if ($deadline && now()->greaterThan($deadline)) {
            return false;
        }

        if ($reservation->status === 'approved') {
            return true;
        }

        if ($reservation->status !== 'converted') {
            return false;
        }

        $registration = $reservation->convertedRegistration;

        return $registration?->status === 'submitted';
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
        $validStatuses = ['submitted', 'approved', 'rejected', 'waitlisted', 'converted', 'expired'];

        $query = DormReservation::with(['candidate', 'period', 'convertedRegistration'])
            ->withCount([
                'reservationPriorities as priority_pending_count' => fn ($q) => $q->where('status', 'pending'),
                'reservationPriorities as priority_verified_count' => fn ($q) => $q->where('status', 'verified'),
                'reservationPriorities as priority_rejected_count' => fn ($q) => $q->where('status', 'rejected'),
            ])
            ->orderByDesc('created_at');

        // Chỉ hiển thị lần nộp MỚI NHẤT của mỗi thí sinh trong cùng 1 đợt đăng ký — tránh
        // liệt kê trùng khi thí sinh bị từ chối rồi nộp lại hồ sơ mới (cùng candidate + đợt).
        // Các lần nộp cũ hơn vẫn xem được qua tab "Lịch sử" (GET .../history). Hồ sơ không
        // gắn candidate (dữ liệu cũ/edge case) không nhóm được nên luôn giữ nguyên.
        $query->where(function ($q) {
            $q->whereNull('admission_candidate_id')
              ->orWhereRaw('id = (
                  select max(dr2.id) from dorm_reservations dr2
                  where dr2.admission_candidate_id = dorm_reservations.admission_candidate_id
                    and dr2.registration_period_id <=> dorm_reservations.registration_period_id
              )');
        });

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

        if ($statusesInput = $request->input('statuses')) {
            $statuses = is_array($statusesInput)
                ? $statusesInput
                : explode(',', (string) $statusesInput);
            $statuses = array_values(array_intersect(
                $validStatuses,
                array_map(static fn ($status) => trim((string) $status), $statuses)
            ));

            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($status = $request->input('status')) {
            if (in_array($status, $validStatuses, true)) {
                $query->where('status', $status);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($registrationStatus = $request->input('registration_status')) {
            if ($registrationStatus === 'cancelled') {
                $query->where('status', 'converted')
                    ->whereHas('convertedRegistration', function ($rq) {
                        $rq->where('status', 'cancelled');
                    });
            } elseif ($registrationStatus === 'not_cancelled') {
                $query->where('status', 'converted')
                    ->whereHas('convertedRegistration', function ($rq) {
                        $rq->where('status', '!=', 'cancelled');
                    });
            }
        }

        // Status 'rejected' dùng chung cho cả "bị admin từ chối" lẫn "thí sinh tự hủy" (xem
        // DormReservationCancellationService), phân biệt qua tiền tố "Tự hủy:" trong
        // rejection_reason — FE cần lọc riêng 2 nhóm này ra 2 tab khác nhau trên danh sách
        // (báo cáo 28/07: lọc "Không được duyệt" đang gộp luôn cả hồ sơ SV tự hủy).
        if ($selfCancelled = $request->input('self_cancelled')) {
            if ($selfCancelled === 'yes') {
                $query->where('status', 'rejected')->where('rejection_reason', 'like', 'Tự hủy:%');
            } elseif ($selfCancelled === 'no') {
                $query->where('status', 'rejected')
                    ->where(function ($q) {
                        $q->whereNull('rejection_reason')->orWhere('rejection_reason', 'not like', 'Tự hủy:%');
                    });
            }
        }

        if ($periodId = $request->input('registration_period_id')) {
            $query->where('registration_period_id', $periodId);
        }

        if ($autoDecision = $request->input('auto_decision')) {
            if (in_array($autoDecision, ['approve', 'waitlist', 'reject', 'review'], true)) {
                $query->where('auto_decision', $autoDecision);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($request->boolean('auto_decision_empty')) {
            $query->whereNull('auto_decision');
        }

        // Lọc theo giới tính candidate — giường KTX tách riêng theo giới ở cấp Floor, FE cần
        // đếm riêng số hồ sơ đề xuất duyệt của từng giới (xem PriorityRankingService::rankPeriod()).
        if ($gender = $request->input('gender')) {
            if (in_array($gender, ['male', 'female'], true)) {
                $query->whereHas('candidate', fn ($q) => $q->where('gender', $gender));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Việc 5 — lọc riêng nhóm "đã duyệt nhưng cuối cùng không thành đơn nội trú" (không
        // tin FE, backend tự AND cứng với status=expired nếu FE lỡ gửi expiration_reason mà
        // quên status, tránh trả nhầm cả submitted/waitlisted chưa expired).
        if ($expirationReason = $request->input('expiration_reason')) {
            $query->where('expiration_reason', $expirationReason)->where('status', 'expired');
        }

        // Lọc theo tình trạng minh chứng ưu tiên tổng hợp (badge FE đang hiển thị). Riêng
        // "Không hợp lệ" phải lấy cả hồ sơ đã bị chuyển sang rejected vì minh chứng bị từ chối.
        // Các cột priority_*_count là subquery scalar từ withCount ở trên nên HAVING dùng được
        // mà không cần GROUP BY.
        if ($priorityEvidenceStatus = $request->input('priority_evidence_status')) {
            if ($priorityEvidenceStatus === 'pending') {
                $query->whereIn('status', ['submitted', 'waitlisted'])
                    ->having('priority_rejected_count', 0)
                    ->having('priority_pending_count', '>', 0);
            } elseif ($priorityEvidenceStatus === 'verified') {
                $query->whereIn('status', ['submitted', 'waitlisted'])
                    ->having('priority_pending_count', 0)
                    ->having('priority_rejected_count', 0)
                    ->having('priority_verified_count', '>', 0);
            } elseif ($priorityEvidenceStatus === 'rejected') {
                $query->having('priority_rejected_count', '>', 0);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $paginated = $query->paginate(20);

        // Tổng hợp tình trạng minh chứng ưu tiên cho FE (badge thay cho "Đã nộp hồ sơ") từ
        // 3 count đã eager-load qua withCount ở trên — không query thêm theo từng hồ sơ.
        // Ưu tiên hiển thị: có minh chứng bị từ chối > còn minh chứng chờ xác minh > đã xác minh.
        $paginated->getCollection()->transform(function (DormReservation $reservation) {
            $reservation->has_priority_evidence = ($reservation->priority_pending_count
                + $reservation->priority_verified_count
                + $reservation->priority_rejected_count) > 0;

            $reservation->priority_evidence_status = match (true) {
                $reservation->priority_rejected_count > 0 => 'rejected',
                $reservation->priority_pending_count > 0 => 'pending',
                $reservation->priority_verified_count > 0 => 'verified',
                default => null,
            };

            return $reservation;
        });

        return response()->json($paginated);
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

        foreach ($reservation->reservationPriorities as $rp) {
            foreach ($rp->evidences as $ev) {
                $ev->file_url = $this->getImageUrl($ev->file_url);
            }
        }

        return response()->json($reservation);
    }

    /**
     * Toàn bộ lịch sử các lần nộp hồ sơ giữ chỗ của cùng 1 thí sinh (mọi đợt đăng ký),
     * dùng cho tab "Lịch sử" ở modal chi tiết — vì index() giờ chỉ trả về bản mới nhất
     * của mỗi (candidate, đợt), các lần nộp cũ hơn (VD: bị từ chối rồi nộp lại) không
     * còn xuất hiện trực tiếp trong danh sách nữa.
     */
    public function history(int $id): JsonResponse
    {
        $reservation = DormReservation::findOrFail($id);

        if (!$reservation->admission_candidate_id) {
            return response()->json(['data' => [$reservation->load(['candidate', 'period'])]]);
        }

        $history = DormReservation::with(['candidate', 'period'])
            ->where('admission_candidate_id', $reservation->admission_candidate_id)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $history]);
    }

    /** Message lỗi capacity dùng chung — nhất quán giữa các endpoint duyệt (BUG-2/RR-2/RR-3). */
    private const CAPACITY_CONFLICT_MESSAGE = 'Không thể duyệt hồ sơ vì sức chứa KTX đã thay đổi hoặc không còn suất trống. Vui lòng tải lại dữ liệu.';

    public function approve(Request $request, int $id): JsonResponse
    {
        $adminNoteInput = $request->input('admin_note');

        // Lock order thống nhất toàn hệ thống: RegistrationPeriod trước, rồi tới
        // DormReservation/Registration — xem DormWaitlistPromotionService (lock period rồi
        // mới lock tập reservation) để tránh deadlock khi 2 luồng giao nhau.
        $result = DB::transaction(function () use ($id, $adminNoteInput) {
            $reservation = DormReservation::with('candidate')->lockForUpdate()->findOrFail($id);

            $period = $reservation->registration_period_id
                ? RegistrationPeriod::where('id', $reservation->registration_period_id)->lockForUpdate()->first()
                : null;

            if ($period && $this->admissionPeriodPastDeadline($period)) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => [
                        'message' => 'Đợt đăng ký giữ chỗ KTX đã kết thúc lúc 17:00 ngày '
                            . $period->admissionDeadline()->format('d/m/Y') . '.',
                    ],
                ];
            }

            // Re-check trạng thái SAU khi đã lock — hồ sơ có thể đã bị duyệt/hủy/từ chối bởi
            // request khác trong lúc chờ lock (idempotent: không duyệt lại, trả lỗi rõ).
            if (!in_array($reservation->status, ['submitted', 'waitlisted'], true)) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => ['message' => 'Hồ sơ hiện không ở trạng thái có thể duyệt (có thể đã được xử lý bởi thao tác khác). Vui lòng tải lại dữ liệu.'],
                ];
            }

            $pendingPriorityCount = ReservationPriority::where('dorm_reservation_id', $reservation->id)
                ->where('status', 'pending')
                ->count();

            if ($pendingPriorityCount > 0) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => [
                        'message' => "Còn {$pendingPriorityCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh hoặc từ chối tất cả minh chứng trước khi duyệt.",
                        'pending_priority_count' => $pendingPriorityCount,
                    ],
                ];
            }

            // Minh chứng ưu tiên bị từ chối — hồ sơ không được duyệt (thường status đã tự
            // chuyển 'rejected' ngay khi admin từ chối minh chứng; check này chỉ để phòng
            // vệ thêm với dữ liệu cũ chưa cascade).
            if ($reservation->hasRejectedPriorityEvidence()) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => ['message' => 'Không thể duyệt hồ sơ vì minh chứng ưu tiên không hợp lệ.'],
                ];
            }

            // Candidate chưa có giới tính (khác Student, gender ở đây nullable) — không tự
            // suy đoán được thuộc suất nam hay nữ, phải chặn duyệt tới khi admin điền đủ.
            $candidateGender = strtolower($reservation->candidate?->gender ?? '');
            if (!in_array($candidateGender, ['male', 'female'], true)) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => ['message' => 'Ứng viên chưa có giới tính hợp lệ trong hồ sơ. Vui lòng cập nhật giới tính trước khi duyệt.'],
                ];
            }

            // Tính capacity SAU KHI đã lock RegistrationPeriod — chặn 2 request approve()
            // đọc cùng 1 giá trị "còn suất" trước khi cả hai cùng ghi approved (BUG-2). Tính
            // RIÊNG theo giới của candidate — giường tách theo giới ở cấp Floor.
            $capacity = app(DormCapacityService::class)->summarizeForRegistrationPeriod($period ?? $reservation->registration_period_id, gender: $candidateGender);
            if (($capacity['available_approval_slots'] ?? 0) < 1) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => ['message' => self::CAPACITY_CONFLICT_MESSAGE, 'capacity' => $capacity],
                ];
            }

        $reservation->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'auto_decision' => null,
            'auto_decision_reason' => null,
            'admin_note'  => $adminNoteInput ?? $reservation->admin_note,
        ]);

            $converted = null;
            // Candidate đã nhập học từ trước (Student đã tồn tại) — tự chuyển luôn thành
            // Registration, không cần đợi import lại Excel. convert() tự mở transaction lồng
            // (savepoint) — giữ nguyên logic hiện có, không đổi hành vi.
            if ($reservation->candidate?->status === 'enrolled' && $reservation->candidate?->student_id) {
                $converted = app(DormReservationConversionService::class)->convert($reservation);
            }

            return [
                'ok'          => true,
                'reservation' => $reservation->fresh(['candidate', 'period']),
                'converted'   => $converted,
            ];
        });

        if (!$result['ok']) {
            return response()->json($result['payload'], $result['status']);
        }

        // Gửi thông báo SAU khi transaction đã commit — không giữ lock trong lúc gửi mail.
        $reservation = $result['reservation'];
        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đã được duyệt',
            $this->approvedNotificationContent($reservation),
        );

        return response()->json(['message' => 'Đã duyệt hồ sơ giữ chỗ.', 'reservation' => $reservation]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with(['candidate', 'period'])->findOrFail($id);

        if ($blocked = $this->ensureAdmissionPeriodStillOpen($reservation)) {
            return $blocked;
        }

        // Hồ sơ đã duyệt ("approved") là trạng thái cuối — không cho từ chối lại để tránh
        // đảo ngược một quyết định duyệt đã có hiệu lực (sinh viên đã được cấp chỗ).
        if (!in_array($reservation->status, ['submitted', 'waitlisted'], true)) {
            return response()->json(['message' => 'Không thể từ chối hồ sơ ở trạng thái hiện tại.'], 422);
        }

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $reservation->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
            'auto_decision'    => null,
            'auto_decision_reason' => null,
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
        $reservation = DormReservation::with(['candidate', 'period'])->findOrFail($id);

        if ($blocked = $this->ensureAdmissionPeriodStillOpen($reservation)) {
            return $blocked;
        }

        if ($reservation->status !== 'submitted') {
            return response()->json(['message' => 'Chỉ chuyển được hồ sơ đã nộp vào danh sách chờ.'], 422);
        }

        if ($reservation->hasRejectedPriorityEvidence()) {
            return response()->json(['message' => 'Không thể duyệt hồ sơ vì minh chứng ưu tiên không hợp lệ.'], 422);
        }

        $reservation->update([
            'status'     => 'waitlisted',
            'auto_decision' => null,
            'auto_decision_reason' => null,
            'admin_note' => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đang chờ xét duyệt',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn hiện đang ở danh sách chờ do số lượng chỗ có hạn. Vui lòng theo dõi thông báo tiếp theo.',
        );

        return response()->json(['message' => 'Đã chuyển vào danh sách chờ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function updateAutoDecision(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,waitlist,reject,review'],
            'reason'   => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['decision'] === 'reject' && trim((string) ($data['reason'] ?? '')) === '') {
            return response()->json([
                'message' => 'Vui lòng nhập lý do từ chối.',
                'errors'  => ['reason' => ['Vui lòng nhập lý do từ chối.']],
            ], 422);
        }

        $reservation = DormReservation::with(['candidate', 'period'])->findOrFail($id);

        if (!in_array($reservation->status, ['submitted', 'waitlisted'], true)) {
            return response()->json(['message' => 'Chỉ đổi được đề xuất cho hồ sơ chưa có quyết định cuối cùng.'], 422);
        }

        // Thiếu check này khiến admin đổi tay đề xuất 'approve'/'waitlist' được ngay cả khi
        // hồ sơ còn minh chứng ưu tiên chưa xác minh — tier/điểm dùng để xếp hạng lúc đó chưa
        // phản ánh đúng thực tế (minh chứng pending không được tính), dẫn tới "Gợi ý duyệt"
        // sai lệch hiển thị trên UI dù còn minh chứng pending (báo cáo 24/07). 'reject' vẫn
        // cho phép bất kể minh chứng, đồng bộ với rule ở approve()/dropdown FE.
        if (in_array($data['decision'], ['approve', 'waitlist'], true)) {
            $pendingPriorityCount = ReservationPriority::where('dorm_reservation_id', $reservation->id)
                ->where('status', 'pending')
                ->count();

            if ($pendingPriorityCount > 0) {
                return response()->json([
                    'message' => "Còn {$pendingPriorityCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh hoặc từ chối tất cả minh chứng trước khi đổi đề xuất sang duyệt/danh sách chờ.",
                    'pending_priority_count' => $pendingPriorityCount,
                ], 422);
            }
        }

        $reservation->update([
            'auto_decision'        => $data['decision'],
            'auto_decision_reason' => trim((string) ($data['reason'] ?? '')) ?: null,
        ]);

        return response()->json([
            'message'     => 'Đã cập nhật đề xuất.',
            'reservation' => $reservation->fresh(['candidate', 'period', 'convertedRegistration']),
        ]);
    }

    public function confirmDecision(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($id) {
            $reservation = DormReservation::with(['candidate', 'period'])->lockForUpdate()->findOrFail($id);

            if ($blocked = $this->ensureAdmissionPeriodStillOpen($reservation)) {
                return ['ok' => false, 'response' => $blocked];
            }

            if (!in_array($reservation->status, ['submitted', 'waitlisted'], true)) {
                return ['ok' => false, 'response' => response()->json(['message' => 'Hồ sơ hiện không ở trạng thái chờ xác nhận.'], 422)];
            }

            if (!in_array($reservation->auto_decision, ['approve', 'waitlist', 'reject'], true)) {
                return ['ok' => false, 'response' => response()->json(['message' => 'Hồ sơ chưa có đề xuất có thể xác nhận.'], 422)];
            }

            $period = $reservation->registration_period_id
                ? RegistrationPeriod::where('id', $reservation->registration_period_id)->lockForUpdate()->first()
                : null;

            if (in_array($reservation->auto_decision, ['approve', 'waitlist'], true)) {
                $pendingPriorityCount = ReservationPriority::where('dorm_reservation_id', $reservation->id)
                    ->where('status', 'pending')
                    ->count();

                if ($pendingPriorityCount > 0) {
                    return ['ok' => false, 'response' => response()->json([
                        'message' => "Còn {$pendingPriorityCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh hoặc từ chối tất cả minh chứng trước khi xác nhận.",
                        'pending_priority_count' => $pendingPriorityCount,
                    ], 422)];
                }

                if ($reservation->hasRejectedPriorityEvidence()) {
                    return ['ok' => false, 'response' => response()->json(['message' => 'Không thể xác nhận hồ sơ vì minh chứng ưu tiên không hợp lệ.'], 422)];
                }
            }

            $notify = null;
            $converted = null;

            if ($reservation->auto_decision === 'approve') {
                $candidateGender = strtolower($reservation->candidate?->gender ?? '');
                if (!in_array($candidateGender, ['male', 'female'], true)) {
                    return ['ok' => false, 'response' => response()->json(['message' => 'Ứng viên chưa có giới tính hợp lệ trong hồ sơ. Vui lòng cập nhật giới tính trước khi xác nhận.'], 422)];
                }

                $capacity = app(DormCapacityService::class)->summarizeForRegistrationPeriod($period ?? $reservation->registration_period_id, gender: $candidateGender);
                if (($capacity['available_approval_slots'] ?? 0) < 1) {
                    return ['ok' => false, 'response' => response()->json(['message' => self::CAPACITY_CONFLICT_MESSAGE, 'capacity' => $capacity], 422)];
                }

                $reservation->update([
                    'status'               => 'approved',
                    'approved_at'          => now(),
                    'auto_decision'        => null,
                    'auto_decision_reason' => null,
                ]);

                if ($reservation->candidate?->status === 'enrolled' && $reservation->candidate?->student_id) {
                    $converted = app(DormReservationConversionService::class)->convert($reservation);
                }

                $notify = [
                    'title' => 'Hồ sơ giữ chỗ KTX đã được duyệt',
                    'content' => $this->approvedNotificationContent($reservation),
                ];
            } elseif ($reservation->auto_decision === 'waitlist') {
                $reservation->update([
                    'status'               => 'waitlisted',
                    'auto_decision'        => null,
                    'auto_decision_reason' => null,
                ]);

                $notify = [
                    'title' => 'Hồ sơ giữ chỗ KTX đang ở danh sách chờ',
                    'content' => 'Hồ sơ đăng ký giữ chỗ KTX của bạn hiện đang ở danh sách chờ do số lượng chỗ có hạn. Vui lòng theo dõi thông báo tiếp theo.',
                ];
            } else {
                $reason = trim((string) $reservation->auto_decision_reason);
                if ($reason === '') {
                    return ['ok' => false, 'response' => response()->json(['message' => 'Vui lòng nhập lý do từ chối trước khi xác nhận.'], 422)];
                }

                $reservation->update([
                    'status'               => 'rejected',
                    'rejection_reason'     => $reason,
                    'auto_decision'        => null,
                    'auto_decision_reason' => null,
                ]);

                $notify = [
                    'title' => 'Hồ sơ giữ chỗ KTX bị từ chối',
                    'content' => 'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị từ chối. Lý do: ' . $reason,
                ];
            }

            return [
                'ok'          => true,
                'reservation' => $reservation->fresh(['candidate', 'period', 'convertedRegistration']),
                'notify'      => $notify,
                'converted'   => $converted,
            ];
        });

        if (!$result['ok']) {
            return $result['response'];
        }

        if ($result['notify']) {
            $this->notifyCandidate(
                $result['reservation']->candidate,
                $result['notify']['title'],
                $result['notify']['content'],
            );
        }

        return response()->json([
            'message'     => 'Đã xác nhận đề xuất.',
            'reservation' => $result['reservation'],
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'message' => 'Chức năng này đã được ngừng sử dụng. Vui lòng dùng Từ chối nếu hồ sơ không được duyệt.',
        ], 410);
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
                ->whereNotIn('status', ['rejected', 'cancelled'])
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

        // Lock RegistrationPeriod TRƯỚC (đúng lock order chung của hệ thống) — 2 request rank
        // cùng 1 đợt sẽ tự serialize qua lock này (request sau chờ, rồi tự đọc lại dữ liệu MỚI
        // bên trong transaction của nó, không có chuyện ghi đè bằng dữ liệu cũ). Không lock
        // trực tiếp từng dorm_reservation vì PriorityRankingService tự làm nhiều vòng
        // query/update (recalculate rồi rank) — lock period là đủ để loại race giữa 2 lần rank,
        // không cần sửa PriorityRankingService (không đổi thuật toán ranking).
        $result = DB::transaction(function () use ($periodId) {
            $period = RegistrationPeriod::where('id', $periodId)->lockForUpdate()->first();

            if (!$period) {
                return ['ok' => false, 'status' => 404, 'payload' => ['message' => 'Không tìm thấy đợt đăng ký.']];
            }

            if ($this->admissionPeriodPastDeadline($period)) {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Đợt đăng ký giữ chỗ KTX đã kết thúc, không thể xếp hạng.']];
            }

            $pendingCount = ReservationPriority::whereHas(
                'dormReservation',
                fn ($q) => $q->where('registration_period_id', $periodId)
            )->where('status', 'pending')->count();

            if ($pendingCount > 0) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => [
                        'message'                => "Còn {$pendingCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh tất cả minh chứng trước khi xếp hạng.",
                        'pending_priority_count' => $pendingCount,
                    ],
                ];
            }

            $rankableCount = DormReservation::where('registration_period_id', $periodId)
                ->where('status', 'submitted')
                ->whereNull('auto_decision')
                ->whereDoesntHave('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
                ->count();

            if ($rankableCount === 0) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => ['message' => 'Đợt này không còn hồ sơ chờ xử lý mới để xếp hạng.'],
                ];
            }

            // Candidate chưa có giới tính hợp lệ không thể xếp vào suất nam/nữ nào — chặn cả
            // đợt tới khi admin điền đủ, giống cách đang chặn khi còn minh chứng pending.
            $genderlessCount = DormReservation::where('registration_period_id', $periodId)
                ->where('status', 'submitted')
                ->whereNull('auto_decision')
                ->whereDoesntHave('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
                ->whereHas('candidate', fn ($q) => $q->whereNotIn('gender', ['male', 'female'])->orWhereNull('gender'))
                ->count();
            if ($genderlessCount > 0) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => ['message' => "Còn {$genderlessCount} hồ sơ ứng viên chưa có giới tính hợp lệ. Vui lòng cập nhật giới tính trước khi xếp hạng."],
                ];
            }

            $capacityService = app(DormCapacityService::class);
            $freeBedsByGender = [
                'male' => (int) $capacityService->summarizeForRegistrationPeriod($period, gender: 'male')['available_approval_slots'],
                'female' => (int) $capacityService->summarizeForRegistrationPeriod($period, gender: 'female')['available_approval_slots'],
            ];

            $ranker = new PriorityRankingService();
            $rankResult = $ranker->rankReservationPeriod($periodId, $freeBedsByGender);
            $capacityWithProposalsByGender = [
                'male' => $capacityService->summarizeForRegistrationPeriod($period, $rankResult['byGender']['male']['approved']->count(), gender: 'male'),
                'female' => $capacityService->summarizeForRegistrationPeriod($period, $rankResult['byGender']['female']['approved']->count(), gender: 'female'),
            ];

            foreach ($rankResult['approved'] as $reservation) {
                $reservation->update([
                    'auto_decision'        => 'approve',
                    'auto_decision_reason' => null,
                ]);
            }
            foreach ($rankResult['waitlist'] as $reservation) {
                $reservation->update([
                    'auto_decision'        => 'waitlist',
                    'auto_decision_reason' => 'Không đủ chỉ tiêu giữ chỗ tại thời điểm xếp hạng.',
                ]);
            }

            return [
                'ok'                  => true,
                'free_beds_by_gender' => $freeBedsByGender,
                'approved_count'      => $rankResult['approved']->count(),
                'waitlist_count'      => $rankResult['waitlist']->count(),
                'capacity_by_gender'  => $capacityWithProposalsByGender,
            ];
        });

        if (!$result['ok']) {
            return response()->json($result['payload'], $result['status']);
        }

        return response()->json([
            'message'             => 'Đã xếp hạng và tạo đề xuất.',
            'free_beds_by_gender' => $result['free_beds_by_gender'],
            'approved'            => $result['approved_count'],
            'waitlist'            => $result['waitlist_count'],
            'capacity_by_gender'  => $result['capacity_by_gender'],
        ]);
    }

    public function confirmRankedReservations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_period_id' => ['required', 'integer', 'exists:registration_periods,id'],
        ]);

        $periodId = (int) $data['registration_period_id'];

        $result = DB::transaction(function () use ($periodId) {
            $period = RegistrationPeriod::where('id', $periodId)->lockForUpdate()->first();

            if (!$period) {
                return ['ok' => false, 'status' => 404, 'payload' => ['message' => 'Không tìm thấy đợt đăng ký.']];
            }

            if ($this->admissionPeriodPastDeadline($period)) {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Đợt đăng ký giữ chỗ KTX đã kết thúc, không thể xác nhận đề xuất.']];
            }

            $reservations = DormReservation::with(['candidate', 'period'])
                ->where('registration_period_id', $periodId)
                ->whereIn('status', ['submitted', 'waitlisted'])
                ->whereIn('auto_decision', ['approve', 'waitlist', 'reject'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Không có đề xuất nào để xác nhận.']];
            }

            $actionableIds = $reservations
                ->whereIn('auto_decision', ['approve', 'waitlist'])
                ->pluck('id');

            if ($actionableIds->isNotEmpty()) {
                $pendingPriorityCount = ReservationPriority::whereIn('dorm_reservation_id', $actionableIds)
                    ->where('status', 'pending')
                    ->count();

                if ($pendingPriorityCount > 0) {
                    return ['ok' => false, 'status' => 422, 'payload' => [
                        'message' => "Còn {$pendingPriorityCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh tất cả minh chứng trước khi xác nhận đề xuất.",
                        'pending_priority_count' => $pendingPriorityCount,
                    ]];
                }

                $rejectedPriorityCount = ReservationPriority::whereIn('dorm_reservation_id', $actionableIds)
                    ->where('status', 'rejected')
                    ->count();

                if ($rejectedPriorityCount > 0) {
                    return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Có hồ sơ trong đề xuất có minh chứng ưu tiên không hợp lệ. Vui lòng tải lại dữ liệu và xử lý lại.']];
                }
            }

            // Kiểm tra RIÊNG theo giới tính candidate — giường tách theo giới ở cấp Floor.
            $approvedReservations = $reservations->where('auto_decision', 'approve');
            foreach (['male', 'female'] as $gender) {
                $genderApproveCount = $approvedReservations
                    ->filter(fn ($r) => strtolower($r->candidate?->gender ?? '') === $gender)
                    ->count();
                if ($genderApproveCount === 0) {
                    continue;
                }
                $capacity = app(DormCapacityService::class)->summarizeForRegistrationPeriod($period, $genderApproveCount, gender: $gender);
                if (($capacity['capacity_exceeded'] ?? false) === true) {
                    return ['ok' => false, 'status' => 422, 'payload' => ['message' => self::CAPACITY_CONFLICT_MESSAGE, 'capacity' => $capacity]];
                }
            }

            $approved = 0;
            $waitlisted = 0;
            $rejected = 0;
            $converted = 0;
            $toNotify = [];

            foreach ($reservations as $reservation) {
                if ($reservation->auto_decision === 'approve') {
                    $reservation->update([
                        'status'               => 'approved',
                        'approved_at'          => now(),
                        'auto_decision'        => null,
                        'auto_decision_reason' => null,
                    ]);

                    if ($reservation->candidate?->status === 'enrolled' && $reservation->candidate?->student_id) {
                        $registration = app(DormReservationConversionService::class)->convert($reservation);
                        if ($registration) {
                            $converted++;
                        }
                    }

                    $toNotify[] = [
                        'reservation' => $reservation->fresh(['candidate', 'period']),
                        'title'       => 'Hồ sơ giữ chỗ KTX đã được duyệt',
                        'content'     => $this->approvedNotificationContent($reservation),
                    ];
                    $approved++;
                } elseif ($reservation->auto_decision === 'waitlist') {
                    $reservation->update([
                        'status'               => 'waitlisted',
                        'auto_decision'        => null,
                        'auto_decision_reason' => null,
                    ]);

                    $toNotify[] = [
                        'reservation' => $reservation->fresh(['candidate', 'period']),
                        'title'       => 'Hồ sơ giữ chỗ KTX đang ở danh sách chờ',
                        'content'     => 'Hồ sơ đăng ký giữ chỗ KTX của bạn hiện đang ở danh sách chờ do số lượng chỗ có hạn. Vui lòng theo dõi thông báo tiếp theo.',
                    ];
                    $waitlisted++;
                } elseif ($reservation->auto_decision === 'reject') {
                    $reason = trim((string) $reservation->auto_decision_reason);
                    if ($reason === '') {
                        return ['ok' => false, 'status' => 422, 'payload' => ['message' => "Hồ sơ #{$reservation->id} chưa có lý do từ chối."]];
                    }

                    $reservation->update([
                        'status'               => 'rejected',
                        'rejection_reason'     => $reason,
                        'auto_decision'        => null,
                        'auto_decision_reason' => null,
                    ]);

                    $toNotify[] = [
                        'reservation' => $reservation->fresh(['candidate', 'period']),
                        'title'       => 'Hồ sơ giữ chỗ KTX bị từ chối',
                        'content'     => 'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị từ chối. Lý do: ' . $reason,
                    ];
                    $rejected++;
                }
            }

            return [
                'ok'         => true,
                'approved'   => $approved,
                'waitlisted' => $waitlisted,
                'rejected'   => $rejected,
                'converted'  => $converted,
                'toNotify'   => $toNotify,
            ];
        });

        if (!$result['ok']) {
            return response()->json($result['payload'], $result['status']);
        }

        foreach ($result['toNotify'] as $item) {
            $this->notifyCandidate(
                $item['reservation']->candidate,
                $item['title'],
                $item['content'],
            );
        }

        return response()->json([
            'message'    => 'Đã xác nhận đề xuất xếp hạng.',
            'approved'   => $result['approved'],
            'waitlisted' => $result['waitlisted'],
            'rejected'   => $result['rejected'],
            'converted'  => $result['converted'],
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
                ->whereNotIn('status', ['rejected', 'cancelled'])
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

    private function approvedNotificationContent(DormReservation $reservation): string
    {
        $deadline = $reservation->period?->admissionDeadline();
        if (!$deadline) {
            return 'Bạn đã được duyệt hồ sơ giữ chỗ KTX. Vui lòng hoàn tất thủ tục nhập học để được xét duyệt đơn đăng ký lưu trú chính thức.';
        }

        return 'Bạn đã được duyệt hồ sơ giữ chỗ KTX. Vui lòng nhập học trước 17:00 ngày '
            . $deadline->format('d/m/Y') . ' để được xét duyệt đơn đăng ký lưu trú chính thức.';
    }

    /**
     * true nếu đợt tân sinh viên đã qua hạn 17:00 end_date — dùng để chặn các action admin
     * làm thay đổi dorm_reservations trong cửa sổ chờ scheduler auto-close (tối đa ~5 phút),
     * không chỉ dựa vào registration_periods.status (có thể lệch vài phút so với deadline
     * thật, xem AutoCloseAdmissionPeriodsCommand). Period không thuộc luồng tân sinh viên
     * (allow_admission_candidates = false) không bị chặn theo quy tắc này.
     */
    private function admissionPeriodPastDeadline(RegistrationPeriod $period): bool
    {
        if (!$period->allow_admission_candidates) {
            return false;
        }

        $deadline = $period->admissionDeadline();

        return $deadline !== null && now()->greaterThan($deadline);
    }

    /**
     * Chặn action admin (approve/reject/waitlist/cancel) làm thay đổi dorm_reservation
     * thuộc đợt tân sinh viên đã qua hạn — trả JsonResponse 422 nếu bị chặn, null nếu
     * còn hợp lệ để xử lý tiếp.
     */
    private function ensureAdmissionPeriodStillOpen(DormReservation $reservation): ?JsonResponse
    {
        $period = $reservation->period;
        if (!$period || !$this->admissionPeriodPastDeadline($period)) {
            return null;
        }

        return response()->json([
            'message' => 'Đợt đăng ký giữ chỗ KTX đã kết thúc lúc 17:00 ngày '
                . $period->admissionDeadline()->format('d/m/Y') . '.',
        ], 422);
    }

    private function notifyCandidate(?AdmissionCandidate $candidate, string $title, string $content): void
    {
        if (!$candidate) {
            return;
        }

        // queue: true — đẩy việc gửi mail (bắt tay SMTP thật với Gmail) ra hàng đợi thay vì
        // gửi đồng bộ, để request duyệt/từ chối/hủy... trả kết quả về FE ngay, không phải
        // chờ SMTP xong (từng khiến 1 lần duyệt mất vài giây tới hàng chục giây).
        app(StudentNotificationService::class)->notifyEmailOnly($candidate->email, $candidate->full_name, $title, $content, queue: true);
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

        $file = $request->file('file');
        $dir  = 'reservation-documents';

        if (StorageHelper::isRailwayWithVolume()) {
            $filename = $request->input('type') . '_' . time() . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
            $fullDir = $volumePath . '/' . $dir;

            if (!file_exists($fullDir)) {
                mkdir($fullDir, 0755, true);
            }

            $file->move($fullDir, $filename);
            $path = $dir . '/' . $filename;
        } else {
            $path = $file->store($dir, 'public');
        }

        $url = '/api/storage/' . $path;

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
