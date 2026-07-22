<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nguồn DUY NHẤT xử lý sinh viên/thí sinh tự hủy nhu cầu ở KTX trước deadline 17:00 của
 * đợt (4 nhánh submitted/waitlisted/approved/converted — xem DormReservationController::
 * cancelSelf()). Không đổi reservation converted về cancelled (giữ lịch sử) — chỉ hủy
 * Registration tương ứng nếu Occupancy chưa ACTIVE. Occupancy ACTIVE thì chặn hoàn toàn,
 * hướng người dùng sang luồng yêu cầu thôi ở (RegistrationController::requestCheckout()).
 */
class DormReservationCancellationService
{
    public const BLOCKED_ACTIVE_OCCUPANCY = 'active_occupancy';
    public const BLOCKED_NOT_CANCELLABLE  = 'not_cancellable';
    public const BLOCKED_PAST_DEADLINE    = 'past_deadline';

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

            if (in_array($reservation->status, ['expired', 'rejected', 'cancelled'], true)) {
                return [
                    'ok'      => false,
                    'code'    => self::BLOCKED_NOT_CANCELLABLE,
                    'message' => 'Hồ sơ hiện không ở trạng thái có thể hủy.',
                ];
            }

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

            $previousStatus        = $reservation->status;
            $releasedApprovedSlot  = false;
            $cancelledRegistration = null;

            if ($reservation->status === 'converted') {
                $registration = $reservation->converted_registration_id
                    ? Registration::where('id', $reservation->converted_registration_id)->lockForUpdate()->first()
                    : null;

                if (!$registration || in_array($registration->status, ['cancelled', 'rejected'], true)) {
                    return [
                        'ok'      => false,
                        'code'    => self::BLOCKED_NOT_CANCELLABLE,
                        'message' => 'Hồ sơ hiện không ở trạng thái có thể hủy.',
                    ];
                }

                $occupancy = Occupancy::where('registration_id', $registration->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($occupancy && $occupancy->status === 'ACTIVE') {
                    return [
                        'ok'      => false,
                        'code'    => self::BLOCKED_ACTIVE_OCCUPANCY,
                        'message' => 'Bạn đã bắt đầu lưu trú. Vui lòng sử dụng chức năng yêu cầu thôi ở.',
                    ];
                }

                // Giữ chỗ/giường tạm (PROPOSED/ROOM_CONFIRMED/PENDING_PAYMENT) nhưng chưa
                // ACTIVE — giải phóng an toàn theo đúng quy ước đã dùng ở
                // RegistrationController::rejectBed() (bed.status='active', occupancy
                // chuyển 'CANCELLED', không xóa bản ghi để giữ lịch sử).
                if ($occupancy && in_array($occupancy->status, ['PROPOSED', 'ROOM_CONFIRMED', 'PENDING_PAYMENT'], true)) {
                    if ($occupancy->bed_id) {
                        // Lock bed trước khi kiểm tra — chống race với selectBed()/assignRoom()
                        // đang gán cùng bed cho người khác đúng lúc này.
                        $bed = Bed::where('id', $occupancy->bed_id)->lockForUpdate()->first();

                        // Chỉ trả bed về 'active' nếu KHÔNG còn occupancy hiệu lực nào khác
                        // (ROOM_CONFIRMED/PENDING_PAYMENT/ACTIVE) đang giữ đúng bed_id này —
                        // tránh giải phóng nhầm giường đã được chuyển cho người khác trong
                        // lúc race, hoặc do dữ liệu có nhiều occupancy trỏ cùng 1 bed.
                        $bedHeldByOther = $bed && Occupancy::where('bed_id', $bed->id)
                            ->where('id', '!=', $occupancy->id)
                            ->whereIn('status', Occupancy::OCCUPIED_BED_STATUSES)
                            ->lockForUpdate()
                            ->exists();

                        if ($bed && !$bedHeldByOther && strtolower((string) $bed->status) !== 'maintenance') {
                            $bed->status = 'active';
                            $bed->save();
                        } elseif ($bedHeldByOther) {
                            Log::warning('[DormReservationCancellationService] Không giải phóng bed vì đang có occupancy hiệu lực khác giữ cùng bed (giữ nguyên bed.status để không ảnh hưởng người đang giữ)', [
                                'bed_id'         => $bed->id,
                                'occupancy_id'   => $occupancy->id,
                                'registration_id' => $registration->id,
                            ]);
                        }
                    }
                    $occupancy->status = 'CANCELLED';
                    $occupancy->save();
                }

                $registration->update([
                    'status'              => 'cancelled',
                    'cancelled_at'        => now(),
                    'cancellation_reason' => $reason,
                    'cancelled_by'        => $cancelledBy,
                ]);

                // Reservation GIỮ NGUYÊN status='converted' để bảo toàn lịch sử — chỉ
                // Registration đổi sang cancelled.
                $cancelledRegistration = $registration;
                $releasedApprovedSlot  = true;
            } else {
                // submitted / waitlisted / approved: hủy thẳng reservation.
                $reservation->update([
                    'status'               => 'cancelled',
                    'cancelled_at'         => now(),
                    'cancellation_reason'  => $reason,
                    'cancelled_by'         => $cancelledBy,
                ]);

                $releasedApprovedSlot = $previousStatus === 'approved';
            }

            return [
                'ok'                     => true,
                'reservation'            => $reservation->fresh(['candidate', 'period']),
                'cancelledRegistration'  => $cancelledRegistration,
                'previousStatus'         => $previousStatus,
                'releasedApprovedSlot'   => $releasedApprovedSlot,
                'periodId'               => $reservation->registration_period_id,
            ];
        });
    }
}
