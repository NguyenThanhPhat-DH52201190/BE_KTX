<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RegistrationPeriodController;
use App\Models\AdminNotification;
use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Services\DormReservationConversionService;
use App\Services\PriorityRankingService;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCloseAdmissionPeriodsCommand extends Command
{
    protected $signature   = 'registration-periods:auto-close-admission';
    protected $description = 'Tự động đóng đợt tân sinh viên khi qua hạn 17:00 end_date: chốt hồ sơ giữ chỗ chưa hoàn tất, đợt → closed';

    public function handle(StudentNotificationService $notifier, DormReservationConversionService $conversionService, PriorityRankingService $ranker): int
    {
        $periods = RegistrationPeriod::where('allow_admission_candidates', true)->get();

        $closedCount = 0;

        foreach ($periods as $period) {
            $deadline = $period->admissionDeadline();
            if (!$deadline || now()->lessThanOrEqualTo($deadline)) {
                continue;
            }

            // Idempotent dựa trên còn hồ sơ chưa xử lý hay không — KHÔNG dựa thuần vào
            // registration_periods.status, vì status có thể đã bị chuyển 'closed' bởi
            // RegistrationPeriodController::syncPeriodStatuses() (chạy trên MỌI request GET
            // /admin/registration-periods, chỉ so end_date với ngày hôm nay, không quan tâm
            // 17:00 hay đơn đã xử lý xong chưa). Nếu admin (hoặc bất kỳ ai) lỡ mở trang danh
            // sách đợt TRƯỚC khi command này kịp chạy, status đã bị đổi 'closed' sẵn — gate
            // theo status lúc đó sẽ bỏ sót vĩnh viễn các đơn đăng ký thường còn 'submitted'
            // (báo cáo 27/07: đợt hiện "đã đóng" trên giao diện nhưng đơn vẫn treo, không ai
            // được báo). Nên phải tự kiểm tra CÒN VIỆC CẦN LÀM hay không (hồ sơ giữ chỗ chưa
            // xử lý, HOẶC đơn đăng ký thường còn 'submitted' ở đợt kênh chính — đợt rolling
            // không qua process()/confirmBatch() nên không tính), bất kể status hiện là gì.
            $hasPending = DormReservation::where('registration_period_id', $period->id)
                ->whereIn('status', ['submitted', 'waitlisted', 'approved'])
                ->exists();

            $hasSubmittedRegistrations = $period->channel === 'main'
                && Registration::where('registration_period_id', $period->id)
                    ->where('status', 'submitted')
                    ->exists();

            if (!$hasPending && !$hasSubmittedRegistrations && $period->status === 'closed') {
                continue;
            }

            if ($this->closePeriod($period, $notifier, $conversionService, $ranker)) {
                $closedCount++;
            }
        }

        $this->info("[registration-periods:auto-close-admission] Đã đóng {$closedCount} đợt.");

        return self::SUCCESS;
    }

    private const PERIOD_CLOSED_SUBMITTED_REJECTION_REASON = 'Hồ sơ không được xử lý trước khi đợt đăng ký kết thúc.';

    /** Map status gốc trước khi expire → expiration_reason tương ứng. */
    private const EXPIRATION_REASON_BY_STATUS = [
        'waitlisted' => DormReservation::EXPIRATION_PERIOD_CLOSED_WAITLISTED,
    ];

    /** @return bool true nếu đợt thực sự đã chuyển `closed` (false nếu bị chặn ở phần Đơn
     *  đăng ký thường — ví dụ còn minh chứng ưu tiên chưa xác minh). */
    private function closePeriod(RegistrationPeriod $period, StudentNotificationService $notifier, DormReservationConversionService $conversionService, PriorityRankingService $ranker): bool
    {
        $result = DB::transaction(function () use ($period, $conversionService) {
            $reservations = DormReservation::where('registration_period_id', $period->id)
                ->whereIn('status', ['submitted', 'waitlisted', 'approved'])
                ->with('candidate')
                ->lockForUpdate()
                ->get();

            $expiredIdsByReason = [];
            $rejectedSubmittedIds = [];
            $anomalyCount = 0;
            $freedSlotsByGender = ['male' => 0, 'female' => 0];

            foreach ($reservations as $reservation) {
                if ($reservation->status === 'submitted') {
                    $rejectedSubmittedIds[] = $reservation->id;
                    continue;
                }

                // waitlisted: hết hạn theo đúng status gốc, vẫn cần tách khỏi nhóm đã duyệt
                // nhưng chưa nhập học để admin lọc rõ nguyên nhân.
                if ($reservation->status === 'waitlisted') {
                    $expiredIdsByReason[self::EXPIRATION_REASON_BY_STATUS[$reservation->status]][] = $reservation->id;
                    continue;
                }

                // approved: LUÔN thử convert trước (dùng chung DormReservationConversionService),
                // bất kể candidate đã enrolled hay chưa — service tự kiểm tra đủ điều kiện.
                $registration = $conversionService->convert($reservation);
                if ($registration) {
                    continue; // đã tự chuyển converted — không expire.
                }

                // Convert thất bại — phân biệt 2 trường hợp:
                // - Candidate đã có Registration hợp lệ khác trong đúng đợt này (duplicate) →
                //   ANOMALY thật (dữ liệu bất thường cần admin kiểm tra tay), KHÔNG expire.
                // - Ngược lại (candidate chưa từng nhập học, hoặc chưa có Registration nào) →
                //   hợp lệ để expire, đúng nghĩa Việc 5 (đã duyệt giữ chỗ nhưng cuối cùng không
                //   thành đơn nội trú trước khi đợt kết thúc).
                $candidate = $reservation->candidate;
                $hasRegistration = $candidate?->student_id
                    ? Registration::where('student_id', $candidate->student_id)
                        ->where('registration_period_id', $reservation->registration_period_id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->exists()
                    : false;

                if ($hasRegistration) {
                    $anomalyCount++;
                    Log::warning('[registration-periods:auto-close-admission] Bỏ qua reservation bất thường (approved, conversion thất bại vì candidate đã có Registration hợp lệ khác trong đợt)', [
                        'period_id'      => $period->id,
                        'reservation_id' => $reservation->id,
                        'candidate_id'   => $reservation->admission_candidate_id,
                    ]);
                    continue;
                }

                $expiredIdsByReason[DormReservation::EXPIRATION_APPROVED_NOT_CONVERTED][] = $reservation->id;
                $expiredGender = strtolower($candidate?->gender ?? '');
                if (in_array($expiredGender, ['male', 'female'], true)) {
                    $freedSlotsByGender[$expiredGender]++;
                }
            }

            $expiredIds = [];
            if ($rejectedSubmittedIds !== []) {
                DormReservation::whereIn('id', $rejectedSubmittedIds)->update([
                    'status'               => 'rejected',
                    'rejection_reason'     => self::PERIOD_CLOSED_SUBMITTED_REJECTION_REASON,
                    'expiration_reason'    => null,
                    'auto_decision'        => null,
                    'auto_decision_reason' => null,
                ]);
            }

            foreach ($expiredIdsByReason as $reason => $ids) {
                DormReservation::whereIn('id', $ids)->update([
                    'status'            => 'expired',
                    'expiration_reason' => $reason,
                ]);
                array_push($expiredIds, ...$ids);
            }

            // KHÔNG ép status → closed ở đây nữa — chỉ đóng đợt THẬT SỰ sau khi đã thử tự
            // động xếp hạng/xác nhận xong phần Registration (xem autoProcessRegistrations()
            // gọi ở cuối closePeriod()). Nếu ép đóng ở đây trước, đợt sẽ hiện "Đã đóng" dù
            // các đơn đăng ký thường bên trong vẫn chưa được xử lý (báo cáo 25/07).

            if ($anomalyCount > 0) {
                $this->warn("  Đợt #{$period->id} \"{$period->name}\": {$anomalyCount} hồ sơ bất thường bị bỏ qua (xem log).");
            }

            return [
                'expired' => $reservations->whereIn('id', $expiredIds),
                'rejected' => $reservations->whereIn('id', $rejectedSubmittedIds),
                'freedSlotsByGender' => $freedSlotsByGender,
            ];
        });

        foreach ($result['expired'] as $reservation) {
            if ($reservation->candidate?->email) {
                $notifier->notifyEmailOnly(
                    $reservation->candidate->email,
                    $reservation->candidate->full_name,
                    'Hồ sơ giữ chỗ KTX đã hết hiệu lực',
                    'Hồ sơ giữ chỗ KTX của bạn đã hết hiệu lực do quá hạn xác nhận nhập học.',
                );
            }
        }

        foreach ($result['rejected'] as $reservation) {
            if ($reservation->candidate?->email) {
                $notifier->notifyEmailOnly(
                    $reservation->candidate->email,
                    $reservation->candidate->full_name,
                    'Hồ sơ giữ chỗ KTX bị từ chối',
                    'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị từ chối. Lý do: ' . self::PERIOD_CLOSED_SUBMITTED_REJECTION_REASON,
                );
            }
        }

        // Phần hồ sơ giữ chỗ đã xử lý xong ở trên (transaction riêng). Giờ mới tới phần
        // Đơn đăng ký thường — thử tự xếp hạng/xác nhận thay admin nếu admin chưa kịp làm,
        // rồi mới ĐÓNG ĐỢT THẬT SỰ (chỉ khi thành công, không ép nếu bị chặn).
        $registrationOutcome = $this->autoProcessRegistrations($period, $notifier);

        $period->refresh();
        if ($registrationOutcome === 'blocked') {
            // status có thể đang là 'closed' do syncPeriodStatuses() lỡ đổi nhầm theo ngày
            // TRƯỚC khi command này chạy tới (xem ghi chú ở handle()) — nếu để nguyên, giao
            // diện sẽ hiện "ĐÃ ĐÓNG" trong khi email/thông báo vừa gửi lại nói "chưa đóng",
            // mâu thuẫn nhau. Trả về đúng 'active' để phản ánh thực tế còn việc cần xử lý
            // tay — an toàn vì cửa nhận đơn vẫn bị khoá bởi chính hạn 17:00 đã qua (xem
            // RegistrationController::findOpenSubmissionPeriod()), không mở lại đợt thật.
            if ($period->status === 'closed') {
                $period->update(['status' => 'active']);
            }
        } elseif ($period->status !== 'closed') {
            $period->update(['status' => 'closed']);
        }

        // Đôn suất giải phóng (từ hồ sơ giữ chỗ hết hạn không nhập học) PHẢI chạy SAU khi
        // autoProcessRegistrations() (rank + confirmBatch) đã xử lý xong đợt — vì ranker
        // đôn người từ nhóm Registration 'rejected', mà nhóm đó chỉ CHỐT XONG (thành 'rejected'
        // thật sự) sau khi confirmBatch() hoàn tất. Chạy trước sẽ đôn nhầm dựa trên dữ liệu
        // 'submitted' chưa xếp hạng, bỏ sót đúng người lẽ ra được đôn (báo cáo 28/07: chạy
        // lệnh xong Võ Quốc Bảo đáng lẽ được đôn lên nhưng không thấy đôn).
        $freedSlotsByGender = $result['freedSlotsByGender'];
        $promotedRegistrations = collect();
        if (max($freedSlotsByGender['male'], $freedSlotsByGender['female']) > 0) {
            $promotedRegistrations = DB::transaction(function () use ($period, $ranker, $freedSlotsByGender) {
                $promoted = $ranker->rankRejectedRegistrationsForFreedSlots($period->id, $freedSlotsByGender);
                foreach ($promoted as $reg) {
                    $reg->update([
                        'status'               => 'approved',
                        'approved_at'          => now(),
                        'auto_decision'        => 'approve',
                        'auto_decision_reason' => null,
                        'rejection_reason'     => null,
                    ]);
                }

                return $promoted;
            });
        }

        foreach ($promotedRegistrations as $registration) {
            if ($registration->student) {
                $this->notifyRegistrationDecision($registration, $notifier);
            }
        }
        if ($promotedRegistrations->count() > 0) {
            $this->notifyAdminEmail(
                "Đã tự động đôn {$promotedRegistrations->count()} đơn lên duyệt — đợt \"{$period->name}\"",
                "Hệ thống vừa tự động đôn {$promotedRegistrations->count()} đơn đăng ký nội trú từ 'Bị từ chối' lên 'Đã duyệt' trong đợt \"{$period->name}\", do có suất giữ chỗ tân sinh viên vừa hết hạn/giải phóng.",
                $notifier,
                'admission_promoted',
                $period->id,
            );
        }

        $isClosed = $period->status === 'closed';
        $closedLabel = $isClosed ? 'đã đóng đợt' : 'CHƯA đóng đợt (còn vướng minh chứng ưu tiên chưa xác minh, xem log/email admin)';
        $this->info("  Đợt #{$period->id} \"{$period->name}\": {$result['expired']->count()} hồ sơ chuyển expired, {$result['rejected']->count()} hồ sơ chuyển rejected, {$promotedRegistrations->count()} đơn được đôn lên duyệt, {$closedLabel}.");

        return $isClosed;
    }

    /**
     * Sau khi hết hạn mà admin chưa "Xếp hạng"/"Xác nhận tất cả" cho Đơn đăng ký thường,
     * hệ thống tự làm thay — tái sử dụng ĐÚNG logic của RegistrationPeriodController::
     * process() và RegistrationController::confirmBatch() (gọi thẳng qua container, không
     * viết lại) để đảm bảo hành vi giống hệt admin tự bấm tay, kể cả các điều kiện chặn có
     * sẵn (ví dụ còn minh chứng ưu tiên chưa xác minh — process() tự trả 422, máy không có
     * thẩm quyền tự xác minh minh chứng nên phải dừng lại, báo admin xử lý tay).
     *
     * @return string 'confirmed' (đã xử lý xong), 'blocked' (bị chặn, đã báo admin, KHÔNG
     *                được đóng đợt), 'nothing' (không có gì cần làm).
     */
    private function autoProcessRegistrations(RegistrationPeriod $period, StudentNotificationService $notifier): string
    {
        // Đợt quanh năm (rolling) không đi qua process()/confirmBatch() (2 API đó chỉ nhận
        // channel 'main') — sinh viên rolling đã nhập học rồi nên không cần chờ hạn 17:00
        // hay tự xếp hạng/xác nhận thay, admin tự xác nhận tay bất cứ lúc nào rồi đóng đợt
        // như cũ. Trả 'nothing' để closePeriod() đóng đợt trực tiếp, không coi là 'blocked'.
        if ($period->channel !== 'main') {
            return 'nothing';
        }

        // Gọi process() cả khi status đang là 'closed' — không chỉ 'active' — vì
        // syncPeriodStatuses() có thể đã đổi status này trước khi command chạy tới (xem
        // ghi chú ở handle()). RegistrationPeriodController::process() tự chấp nhận cả
        // 'active' lẫn 'closed' (chính là cơ chế "Xếp hạng lại" admin vẫn dùng), nên gọi
        // được an toàn ở đây. Chỉ gọi khi THỰC SỰ còn đơn 'submitted' — tránh xếp hạng lại
        // vô ích một đợt đã xử lý xong từ trước.
        if (in_array($period->status, ['active', 'closed'], true)) {
            $hasSubmitted = Registration::where('registration_period_id', $period->id)
                ->where('status', 'submitted')
                ->exists();

            if ($hasSubmitted) {
                $processResponse = app(RegistrationPeriodController::class)->process($period->id);
                if ($processResponse->getStatusCode() !== 200) {
                    $this->notifyAdminBlocked($period, $processResponse, $notifier);

                    return 'blocked';
                }
                $period->refresh();
            }
        }

        if ($period->status !== 'processing') {
            return 'nothing';
        }

        $confirmResponse = app(RegistrationController::class)->confirmBatch($period->id);
        if ($confirmResponse->getStatusCode() !== 200) {
            $this->notifyAdminBlocked($period, $confirmResponse, $notifier);

            return 'blocked';
        }

        $this->notifyAdminEmail(
            "Đã tự động xác nhận đợt \"{$period->name}\" do quá hạn",
            "Đợt đăng ký \"{$period->name}\" đã quá hạn 17:00 ngày {$period->end_date?->format('d/m/Y')} mà chưa được xác nhận, hệ thống đã tự động xếp hạng/xác nhận và đóng đợt thay.",
            $notifier,
            'admission_auto_confirmed',
            $period->id,
        );

        return 'confirmed';
    }

    private function notifyAdminBlocked(RegistrationPeriod $period, $response, StudentNotificationService $notifier): void
    {
        $data = json_decode($response->getContent(), true);
        $message = $data['message'] ?? 'Không rõ lý do';

        Log::warning("[registration-periods:auto-close-admission] Không thể tự động xếp hạng/xác nhận đợt #{$period->id} \"{$period->name}\": {$message}");

        $this->notifyAdminEmail(
            "Đợt \"{$period->name}\" đã quá hạn nhưng chưa thể tự động xác nhận",
            "Đợt đăng ký \"{$period->name}\" đã quá hạn 17:00 ngày {$period->end_date?->format('d/m/Y')} nhưng hệ thống KHÔNG THỂ tự động xử lý. Lý do: {$message}. Vui lòng vào hệ thống xử lý thủ công (đợt hiện chưa bị đóng).",
            $notifier,
            'admission_auto_blocked',
            $period->id,
        );
    }

    /** Gửi email cho admin (đọc từ config('auth.admin_login_email'), lấy từ .env
     *  ADMIN_LOGIN_EMAIL — hiện chưa có bảng tài khoản admin nhiều người nên chỉ có 1 email
     *  cấu hình sẵn) VÀ tạo thông báo chuông trong giao diện admin (bảng admin_notifications,
     *  cùng cơ chế Header.tsx đang dùng cho checkout_requested/support_request_new...) —
     *  admin không nên chỉ biết qua email vì các hành động này chạy khi không ai đang thao
     *  tác trên hệ thống. */
    private function notifyAdminEmail(string $title, string $content, StudentNotificationService $notifier, string $type, ?int $relatedId = null): void
    {
        $adminEmail = config('auth.admin_login_email');
        if ($adminEmail) {
            $notifier->notifyEmailOnly($adminEmail, 'Quản trị viên KTX', $title, $content, queue: true);
        }

        AdminNotification::create([
            'title'      => $title,
            'content'    => $content,
            'type'       => $type,
            'related_id' => $relatedId,
            'created_at' => now(),
        ]);
    }

    /** Báo cho sinh viên khi đơn đăng ký thường của họ được đôn từ 'rejected' lên 'approved'
     *  nhờ suất giữ chỗ vừa giải phóng — cùng nội dung mẫu với
     *  RegistrationController::notifyRegistrationDecision() cho nhánh approved. */
    private function notifyRegistrationDecision(Registration $registration, StudentNotificationService $notifier): void
    {
        $notifier->notifyStudent(
            $registration->student,
            'Đơn đăng ký nội trú đã được duyệt',
            'Đơn đăng ký nội trú KTX của bạn đã được đôn lên duyệt vì có suất trống được giải phóng. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
            'registration_approved',
            $registration->id,
            queue: true,
        );
    }
}
