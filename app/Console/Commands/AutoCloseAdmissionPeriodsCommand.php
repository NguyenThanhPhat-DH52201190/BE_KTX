<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RegistrationPeriodController;
use App\Models\AdminNotification;
use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Services\DormReservationExpiryService;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoCloseAdmissionPeriodsCommand extends Command
{
    protected $signature   = 'registration-periods:auto-close-admission';
    protected $description = 'Tự động đóng đợt tân sinh viên khi qua hạn 17:00 end_date: chốt hồ sơ giữ chỗ chưa hoàn tất, đợt → closed';

    public function handle(StudentNotificationService $notifier, DormReservationExpiryService $expiryService): int
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

            if ($this->closePeriod($period, $notifier, $expiryService)) {
                $closedCount++;
            }
        }

        $this->info("[registration-periods:auto-close-admission] Đã đóng {$closedCount} đợt.");

        return self::SUCCESS;
    }

    /** @return bool true nếu đợt thực sự đã chuyển `closed` (false nếu bị chặn ở phần Đơn
     *  đăng ký thường — ví dụ còn minh chứng ưu tiên chưa xác minh). */
    private function closePeriod(RegistrationPeriod $period, StudentNotificationService $notifier, DormReservationExpiryService $expiryService): bool
    {
        // Hết hạn hồ sơ giữ chỗ TRƯỚC khi xếp hạng Đơn đăng ký thường — để suất vừa hết hạn
        // được tính "trống" ngay trong bảng xếp hạng chính (xem DormReservationExpiryService).
        $expiry = $expiryService->expirePendingReservations($period);

        // Giờ mới tới phần Đơn đăng ký thường — thử tự xếp hạng/xác nhận thay admin nếu admin
        // chưa kịp làm, rồi mới ĐÓNG ĐỢT THẬT SỰ (chỉ khi thành công, không ép nếu bị chặn).
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
        // 'submitted' chưa xếp hạng, bỏ sót đúng người lẽ ra được đôn (báo cáo 28/07, 30/07).
        $promoted = $expiryService->promoteFreedSlots($period, $expiry['freedSlotsByGender']);

        $isClosed = $period->status === 'closed';
        $closedLabel = $isClosed ? 'đã đóng đợt' : 'CHƯA đóng đợt (còn vướng minh chứng ưu tiên chưa xác minh, xem log/email admin)';
        $this->info("  Đợt #{$period->id} \"{$period->name}\": {$expiry['expiredCount']} hồ sơ giữ chỗ hết hạn/từ chối, {$promoted->count()} đơn được đôn lên duyệt, {$closedLabel}.");

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
}
