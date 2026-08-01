<?php

namespace App\Services;

use App\Models\DormReservation;
use App\Models\Registration;
use Illuminate\Support\Facades\DB;

/**
 * Xử lý thí sinh/sinh viên tự hủy hồ sơ giữ chỗ trước deadline 17:00 của đợt.
 *
 * QUAN TRỌNG: reservation chỉ chuyển sang 'converted' ĐÚNG LÚC Registration nguồn được admin
 * "Xác nhận" (approved) — 2 việc này luôn xảy ra CÙNG LÚC trong 1 transaction (xem
 * RegistrationController::confirmSingle()/confirmBatch()/approve()), KHÔNG có giai đoạn trung
 * gian nào mà reservation đã 'converted' trong khi Registration vẫn còn 'submitted'. Vì vậy
 * "reservation đã converted" ĐỒNG NGHĨA với "đơn đã được admin xác nhận chính thức" — chặn
 * tự hủy hoàn toàn ở giai đoạn này theo đúng yêu cầu, hướng sang "Yêu cầu thôi ở"
 * (RegistrationController::requestCheckout()) hoặc liên hệ Ban quản lý.
 *
 * 3 trạng thái xử lý:
 * 1. Reservation 'approved', CHƯA có Registration nào (chưa nhập học) — hủy thẳng trên
 *    reservation, chuyển 'rejected' (không dùng 'cancelled' — trạng thái này đã bị gỡ khỏi
 *    enum có chủ đích, xem migration 2026_07_23_000002_remove_cancelled_status_from_dorm_
 *    reservations — gộp mọi hủy/từ chối hồ sơ giữ chỗ về chung 1 status 'rejected', chỉ khác
 *    nhau ở nội dung rejection_reason).
 * 2. Reservation 'approved', ĐÃ có Registration nguồn (đã nhập học, convert() đã tạo đơn)
 *    nhưng Registration đó còn 'submitted' (chưa được admin xác nhận) — hủy CẢ HAI: Registration
 *    chuyển 'cancelled', reservation chuyển 'rejected' (không để Registration mồ côi trỏ về 1
 *    reservation đã hủy).
 * 3. Reservation 'converted' (đã được admin xác nhận chính thức) — CHẶN, không cho tự hủy nữa.
 */
class DormReservationCancellationService
{
    public const BLOCKED_ALREADY_CONFIRMED = 'already_confirmed';
    public const BLOCKED_NOT_CANCELLABLE   = 'not_cancellable';
    public const BLOCKED_PAST_DEADLINE     = 'past_deadline';

    /**
     * @return array{ok: bool, code?: string, message?: string, reservation?: DormReservation,
     *               cancelledRegistration?: ?Registration, previousStatus?: string,
     *               releasedApprovedSlot?: bool, periodId?: ?int}
     */
    public function cancel(int $reservationId, string $reason, string $cancelledBy): array
    {
        return DB::transaction(function () use ($reservationId, $reason, $cancelledBy) {
            $reservation = DormReservation::with(['candidate', 'period'])
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $period   = $reservation->period;
            $deadline = $period?->admissionDeadline();
            if ($deadline && now()->greaterThan($deadline)) {
                return [
                    'ok'      => false,
                    'code'    => self::BLOCKED_PAST_DEADLINE,
                    'message' => 'Đợt đăng ký giữ chỗ KTX đã kết thúc lúc 17:00 ngày '
                        . $deadline->format('d/m/Y') . ', không thể hủy trực tuyến. Vui lòng liên hệ Ban quản lý KTX nếu cần hỗ trợ.',
                ];
            }

            if ($reservation->status === 'converted') {
                return [
                    'ok'      => false,
                    'code'    => self::BLOCKED_ALREADY_CONFIRMED,
                    'message' => 'Đơn đăng ký nội trú của bạn đã được Ban quản lý KTX xác nhận, không thể tự hủy trực tuyến nữa. Vui lòng dùng chức năng "Yêu cầu thôi ở" hoặc liên hệ Ban quản lý KTX nếu cần hỗ trợ.',
                ];
            }

            if ($reservation->status !== 'approved') {
                return [
                    'ok'      => false,
                    'code'    => self::BLOCKED_NOT_CANCELLABLE,
                    'message' => 'Hồ sơ hiện không ở trạng thái có thể hủy.',
                ];
            }

            return $this->cancelApprovedReservation($reservation, $reason, $cancelledBy);
        });
    }

    /** Reservation đang 'approved' — có thể đã nhập học (có Registration nguồn 'submitted'
     *  chờ xác nhận) hoặc chưa (không có Registration nào). Cả 2 trường hợp đều hủy được, chỉ
     *  khác là trường hợp đầu phải hủy luôn Registration để không mồ côi dữ liệu. */
    private function cancelApprovedReservation(DormReservation $reservation, string $reason, string $cancelledBy): array
    {
        $previousStatus = $reservation->status;

        $pendingRegistration = Registration::where('source_dorm_reservation_id', $reservation->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->with('student:id,gender')
            ->lockForUpdate()
            ->first();

        if ($pendingRegistration) {
            $pendingRegistration->update([
                'status'              => 'cancelled',
                'cancelled_at'        => now(),
                'cancellation_reason' => $reason,
                'cancelled_by'        => $cancelledBy,
            ]);

            // Đơn vừa bị loại khỏi vòng cạnh tranh (status='cancelled') — xếp hạng lại NGAY
            // đúng giới của thí sinh vừa tự hủy để người kế tiếp trong waitlist được đôn lên và
            // hiển thị đúng trên /admin/registrations ngay, không cần đợi admin bấm "Xếp hạng
            // lại" thủ công (xem RegistrationController::manualRejectNow() — cùng cơ chế, áp
            // dụng cho trường hợp Từ chối tay).
            $gender = strtolower($pendingRegistration->student?->gender ?? '');
            if (in_array($gender, ['male', 'female'], true)) {
                $capacityService = app(DormCapacityService::class);
                $availableBedsByGender = [
                    'male' => (int) ($capacityService->summarizeForRegistrationPeriod($reservation->registration_period_id, gender: 'male', excludeReservationsWithRegistration: true)['available_approval_slots'] ?? 0),
                    'female' => (int) ($capacityService->summarizeForRegistrationPeriod($reservation->registration_period_id, gender: 'female', excludeReservationsWithRegistration: true)['available_approval_slots'] ?? 0),
                ];
                app(PriorityRankingService::class)->applyRankingDecisions($reservation->registration_period_id, $availableBedsByGender, onlyGender: $gender);
            }
        }

        $reservation->update([
            'status'               => 'rejected',
            'rejection_reason'     => "Tự hủy: {$reason}",
            'auto_decision'        => null,
            'auto_decision_reason' => null,
        ]);

        return [
            'ok'                    => true,
            'reservation'           => $reservation->fresh(['candidate', 'period']),
            'cancelledRegistration' => $pendingRegistration,
            'previousStatus'        => $previousStatus,
            'releasedApprovedSlot'  => true,
            'periodId'              => $reservation->registration_period_id,
        ];
    }
}
