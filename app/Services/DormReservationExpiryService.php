<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Xử lý hồ sơ giữ chỗ (DormReservation) hết hạn cho đợt kênh 'main' đã qua 17:00
 * admissionDeadline(), tính suất trống theo giới, rồi đôn Registration bị từ chối tương
 * ứng lên duyệt. Tách ra từ AutoCloseAdmissionPeriodsCommand để dùng chung được ở cả cron
 * và nút "Xác nhận tất cả" — trước đây chỉ cron mới đôn được, admin tự bấm tay không đôn
 * (báo cáo 30/07). An toàn gọi nhiều lần (idempotent): reservation/registration đã xử lý
 * xong ở lượt trước sẽ không bị xử lý/đôn lại ở các lượt gọi sau.
 *
 * Lưu ý thứ tự quan trọng (giữ nguyên từ code gốc): expirePendingReservations() nên chạy
 * TRƯỚC khi xếp hạng Đơn đăng ký thường (để suất vừa hết hạn được tính "trống" ngay trong
 * bảng xếp hạng chính, giảm số người phải đôn bù); còn promoteFreedSlots() chỉ được gọi
 * SAU KHI Registration đã chốt hẳn 'rejected' (sau confirmBatch()) — ranker chỉ tìm được
 * người trong nhóm đã chốt thật, gọi sớm hơn sẽ đôn hụt vì chưa ai 'rejected' thật sự.
 */
class DormReservationExpiryService
{
    private const PERIOD_CLOSED_SUBMITTED_REJECTION_REASON = 'Hồ sơ không được xử lý trước khi đợt đăng ký kết thúc.';

    private const EXPIRATION_REASON_BY_STATUS = [
        'waitlisted' => DormReservation::EXPIRATION_PERIOD_CLOSED_WAITLISTED,
    ];

    public function __construct(
        private readonly PriorityRankingService $ranker,
        private readonly DormReservationConversionService $conversionService,
        private readonly StudentNotificationService $notifier,
    ) {
    }

    /**
     * Tiện gọi 1 lần cho nơi không cần tách 2 bước (VD: confirmBatch() — lúc đó Registration
     * đã chốt sẵn từ trước, không có bước xếp hạng nào chen giữa).
     *
     * @return array{expiredCount: int, promotedCount: int}
     */
    public function processExpiredReservationsAndPromote(RegistrationPeriod $period): array
    {
        $expiry = $this->expirePendingReservations($period);
        $promoted = $this->promoteFreedSlots($period, $expiry['freedSlotsByGender']);

        return [
            'expiredCount'  => $expiry['expiredCount'],
            'promotedCount' => $promoted->count(),
        ];
    }

    /**
     * @return array{expiredCount: int, freedSlotsByGender: array{male: int, female: int}}
     */
    public function expirePendingReservations(RegistrationPeriod $period): array
    {
        if ($period->channel !== 'main' || ! $period->allow_admission_candidates) {
            return ['expiredCount' => 0, 'freedSlotsByGender' => ['male' => 0, 'female' => 0]];
        }

        $deadline = $period->admissionDeadline();
        if (! $deadline || now()->lessThanOrEqualTo($deadline)) {
            return ['expiredCount' => 0, 'freedSlotsByGender' => ['male' => 0, 'female' => 0]];
        }

        $result = DB::transaction(function () use ($period) {
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

                if ($reservation->status === 'waitlisted') {
                    $expiredIdsByReason[self::EXPIRATION_REASON_BY_STATUS[$reservation->status]][] = $reservation->id;
                    continue;
                }

                // approved: LUÔN thử convert trước, bất kể candidate đã enrolled hay chưa —
                // service tự kiểm tra đủ điều kiện.
                $registration = $this->conversionService->convert($reservation);
                if ($registration) {
                    continue;
                }

                $candidate = $reservation->candidate;
                $hasRegistration = $candidate?->student_id
                    ? Registration::where('student_id', $candidate->student_id)
                        ->where('registration_period_id', $reservation->registration_period_id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->exists()
                    : false;

                if ($hasRegistration) {
                    $anomalyCount++;
                    Log::warning('[DormReservationExpiryService] Bỏ qua reservation bất thường (approved, conversion thất bại vì candidate đã có Registration hợp lệ khác trong đợt)', [
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

            if ($anomalyCount > 0) {
                Log::warning("[DormReservationExpiryService] Đợt #{$period->id} \"{$period->name}\": {$anomalyCount} hồ sơ bất thường bị bỏ qua.");
            }

            return [
                'expired'            => $reservations->whereIn('id', $expiredIds),
                'rejected'           => $reservations->whereIn('id', $rejectedSubmittedIds),
                'freedSlotsByGender' => $freedSlotsByGender,
            ];
        });

        foreach ($result['expired'] as $reservation) {
            if ($reservation->candidate?->email) {
                $this->notifier->notifyEmailOnly(
                    $reservation->candidate->email,
                    $reservation->candidate->full_name,
                    'Hồ sơ giữ chỗ KTX đã hết hiệu lực',
                    'Hồ sơ giữ chỗ KTX của bạn đã hết hiệu lực do quá hạn xác nhận nhập học.',
                );
            }
        }

        foreach ($result['rejected'] as $reservation) {
            if ($reservation->candidate?->email) {
                $this->notifier->notifyEmailOnly(
                    $reservation->candidate->email,
                    $reservation->candidate->full_name,
                    'Hồ sơ giữ chỗ KTX bị từ chối',
                    'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị từ chối. Lý do: ' . self::PERIOD_CLOSED_SUBMITTED_REJECTION_REASON,
                );
            }
        }

        return [
            'expiredCount'       => count($result['expired']),
            'freedSlotsByGender' => $result['freedSlotsByGender'],
        ];
    }

    /** @param array{male: int, female: int} $freedSlotsByGender */
    public function promoteFreedSlots(RegistrationPeriod $period, array $freedSlotsByGender): Collection
    {
        if (max($freedSlotsByGender['male'], $freedSlotsByGender['female']) <= 0) {
            return collect();
        }

        $promoted = DB::transaction(function () use ($period, $freedSlotsByGender) {
            $promoted = $this->ranker->rankRejectedRegistrationsForFreedSlots($period->id, $freedSlotsByGender);
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

        foreach ($promoted as $registration) {
            if ($registration->student) {
                $this->notifier->notifyStudent(
                    $registration->student,
                    'Đơn đăng ký nội trú đã được duyệt',
                    'Đơn đăng ký nội trú KTX của bạn đã được đôn lên duyệt vì có suất trống được giải phóng. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
                    'registration_approved',
                    $registration->id,
                    queue: true,
                );
            }
        }

        if ($promoted->count() > 0) {
            $adminEmail = config('auth.admin_login_email');
            $title = "Đã tự động đôn {$promoted->count()} đơn lên duyệt — đợt \"{$period->name}\"";
            $content = "Hệ thống vừa tự động đôn {$promoted->count()} đơn đăng ký nội trú từ 'Bị từ chối' lên 'Đã duyệt' trong đợt \"{$period->name}\", do có suất giữ chỗ tân sinh viên vừa hết hạn/giải phóng.";

            if ($adminEmail) {
                $this->notifier->notifyEmailOnly($adminEmail, 'Quản trị viên KTX', $title, $content, queue: true);
            }

            AdminNotification::create([
                'title'      => $title,
                'content'    => $content,
                'type'       => 'admission_promoted',
                'related_id' => $period->id,
                'created_at' => now(),
            ]);
        }

        return $promoted;
    }
}
