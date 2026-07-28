<?php

namespace App\Services;

use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tự động đôn ĐÚNG 1 hồ sơ waitlisted lên approved khi có 1 suất được giải phóng
 * (approved/converted bị hủy trước deadline — xem DormReservationCancellationService).
 * Không tự đôn nếu đã qua deadline 17:00 của đợt. Thứ tự chọn dùng đúng ranking hiện có
 * của PriorityRankingService (top_priority_tier ASC, total_priority_score DESC,
 * created_at ASC, id ASC) — không tính lại rank number vì hệ thống không lưu/hiển thị rank
 * liên tục, chỉ sort tại thời điểm truy vấn.
 */
class DormWaitlistPromotionService
{
    public function __construct(
        private DormReservationConversionService $conversionService,
        private DormCapacityService $capacityService,
    )
    {
    }

    /**
     * @param string $gender 'male'|'female' — GIỚI CỦA SUẤT VỪA GIẢI PHÓNG (giới của hồ sơ
     *                       vừa bị hủy) — chỉ đôn người waitlisted CÙNG GIỚI lên thế chỗ, vì
     *                       giường tách riêng theo giới ở cấp Floor (xem
     *                       PriorityRankingService::rankPeriod() để biết lý do).
     * @return array{promoted: bool, reason?: string, reservation?: DormReservation, converted?: bool, registration?: ?Registration}
     */
    public function promoteOne(int $periodId, string $gender): array
    {
        return DB::transaction(function () use ($periodId, $gender) {
            $period = RegistrationPeriod::where('id', $periodId)->lockForUpdate()->first();
            if (!$period) {
                return ['promoted' => false, 'reason' => 'period_not_found'];
            }

            $deadline = $period->admissionDeadline();
            if ($deadline && now()->greaterThan($deadline)) {
                return ['promoted' => false, 'reason' => 'past_deadline'];
            }

            $capacity = $this->capacityService->summarizeForRegistrationPeriod($period, gender: $gender);
            if (($capacity['available_approval_slots'] ?? 0) < 1) {
                return ['promoted' => false, 'reason' => 'no_capacity'];
            }

            // Lock cả nhóm waitlisted theo đúng thứ tự ranking hiện có (không persist rank
            // number) rồi chọn hồ sơ hợp lệ đầu tiên trong PHP — loại candidate không còn
            // hợp lệ (đã bị hủy trúng tuyển) hoặc có Registration hợp lệ khác trùng đợt
            // (duplicate/race) thay vì đôn nhầm. Chỉ xét candidate CÙNG GIỚI với suất vừa
            // giải phóng.
            $candidates = DormReservation::where('registration_period_id', $periodId)
                ->where('status', 'waitlisted')
                ->whereHas('candidate', fn ($q) => $q->where('gender', $gender))
                ->with('candidate')
                ->orderBy('top_priority_tier', 'asc')
                ->orderByDesc('total_priority_score')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $chosen = null;
            foreach ($candidates as $reservation) {
                $admissionCandidate = $reservation->candidate;

                if (!$admissionCandidate || $admissionCandidate->status === 'cancelled') {
                    continue;
                }

                // Minh chứng ưu tiên bị từ chối/còn chờ xác minh — không được đôn (dù đang
                // waitlisted do dữ liệu cũ chưa cascade). Log rõ để không đôn nhầm.
                if ($reservation->hasRejectedPriorityEvidence()) {
                    Log::warning('[DormWaitlistPromotionService] Bỏ qua hồ sơ waitlisted có minh chứng ưu tiên không hợp lệ', [
                        'period_id'      => $periodId,
                        'reservation_id' => $reservation->id,
                        'candidate_id'   => $admissionCandidate->id,
                    ]);
                    continue;
                }

                if ($reservation->reservationPriorities()->where('status', 'pending')->exists()) {
                    Log::warning('[DormWaitlistPromotionService] Bỏ qua hồ sơ waitlisted còn minh chứng ưu tiên chưa xác minh', [
                        'period_id'      => $periodId,
                        'reservation_id' => $reservation->id,
                        'candidate_id'   => $admissionCandidate->id,
                    ]);
                    continue;
                }

                if ($admissionCandidate->student_id) {
                    $hasActiveRegistration = Registration::where('student_id', $admissionCandidate->student_id)
                        ->where('registration_period_id', $periodId)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->exists();

                    if ($hasActiveRegistration) {
                        Log::warning('[DormWaitlistPromotionService] Bỏ qua hồ sơ waitlisted bất thường (candidate đã có Registration hợp lệ khác trùng đợt)', [
                            'period_id'      => $periodId,
                            'reservation_id' => $reservation->id,
                            'candidate_id'   => $admissionCandidate->id,
                        ]);
                        continue;
                    }
                }

                $chosen = $reservation;
                break;
            }

            if (!$chosen) {
                return ['promoted' => false, 'reason' => 'no_eligible_waitlist'];
            }

            $chosen->update([
                'status'      => 'approved',
                'approved_at' => now(),
            ]);

            $registration = null;
            $converted    = false;

            // Nếu candidate đã enrolled (Student đã tồn tại) — tự chuyển luôn thành
            // Registration, không cần chờ import lại Excel (đúng nghiệp vụ Việc 6).
            $admissionCandidate = $chosen->candidate;
            if ($admissionCandidate?->status === 'enrolled' && $admissionCandidate?->student_id) {
                // notify:false — controller gọi promoteOne() sẽ tự gửi 1 thông báo tổng hợp
                // duy nhất cho người được đôn, không để convert() gửi thêm thông báo riêng
                // trùng ý (xem DormReservationConversionService::convert()).
                $registration = $this->conversionService->convert($chosen->fresh(), notify: false);
                $converted    = (bool) $registration;

                if (!$converted) {
                    Log::warning('[DormWaitlistPromotionService] Đôn waitlisted thành approved OK nhưng convert() thất bại — giữ approved, không expire/hủy, chờ admin xử lý hoặc import nhập học.', [
                        'period_id'      => $periodId,
                        'reservation_id' => $chosen->id,
                        'candidate_id'   => $admissionCandidate->id,
                    ]);
                }
            }

            return [
                'promoted'     => true,
                'reservation'  => $chosen->fresh(['candidate', 'period']),
                'converted'    => $converted,
                'registration' => $registration,
            ];
        });
    }
}
