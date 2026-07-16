<?php

namespace App\Console\Commands;

use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Services\DormReservationConversionService;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCloseAdmissionPeriodsCommand extends Command
{
    protected $signature   = 'registration-periods:auto-close-admission';
    protected $description = 'Tự động đóng đợt tân sinh viên khi qua hạn 17:00 end_date: hồ sơ giữ chỗ chưa converted → expired, đợt → closed';

    public function handle(StudentNotificationService $notifier, DormReservationConversionService $conversionService): int
    {
        $periods = RegistrationPeriod::where('allow_admission_candidates', true)->get();

        $closedCount = 0;

        foreach ($periods as $period) {
            $deadline = $period->admissionDeadline();
            if (!$deadline || now()->lessThanOrEqualTo($deadline)) {
                continue;
            }

            // Idempotent dựa trên còn hồ sơ chưa xử lý hay không — KHÔNG dựa vào
            // registration_periods.status, vì status có thể đã bị chuyển 'closed' bởi
            // cơ chế khác (syncPeriodStatuses()/periods:update-status chạy độc lập, chỉ
            // đồng bộ status theo ngày, không đụng tới dorm_reservations). Nếu chỉ gate
            // theo status, hồ sơ sẽ bị bỏ sót vĩnh viễn một khi status đã bị nơi khác
            // chuyển 'closed' trước khi command này kịp chạy.
            $hasPending = DormReservation::where('registration_period_id', $period->id)
                ->whereIn('status', ['submitted', 'waitlisted', 'approved'])
                ->exists();

            if (!$hasPending && $period->status === 'closed') {
                continue;
            }

            $this->closePeriod($period, $notifier, $conversionService);
            $closedCount++;
        }

        $this->info("[registration-periods:auto-close-admission] Đã đóng {$closedCount} đợt.");

        return self::SUCCESS;
    }

    /** Map status gốc trước khi expire → expiration_reason tương ứng (submitted/waitlisted). */
    private const EXPIRATION_REASON_BY_STATUS = [
        'submitted'  => DormReservation::EXPIRATION_PERIOD_CLOSED_SUBMITTED,
        'waitlisted' => DormReservation::EXPIRATION_PERIOD_CLOSED_WAITLISTED,
    ];

    private function closePeriod(RegistrationPeriod $period, StudentNotificationService $notifier, DormReservationConversionService $conversionService): void
    {
        $expiredReservations = DB::transaction(function () use ($period, $conversionService) {
            $reservations = DormReservation::where('registration_period_id', $period->id)
                ->whereIn('status', ['submitted', 'waitlisted', 'approved'])
                ->with('candidate')
                ->lockForUpdate()
                ->get();

            $expiredIdsByReason = [];
            $anomalyCount = 0;

            foreach ($reservations as $reservation) {
                // submitted/waitlisted: hết hạn bình thường theo đúng status gốc — không còn
                // coi candidate.status=enrolled là điều kiện đặc biệt (candidate đã nhập học
                // rồi mà reservation này vẫn submitted/waitlisted là tình huống hợp lệ, không
                // phải anomaly; Student/candidate.status giữ nguyên).
                if ($reservation->status !== 'approved') {
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
            }

            $expiredIds = [];
            foreach ($expiredIdsByReason as $reason => $ids) {
                DormReservation::whereIn('id', $ids)->update([
                    'status'            => 'expired',
                    'expiration_reason' => $reason,
                ]);
                array_push($expiredIds, ...$ids);
            }

            $period->update(['status' => 'closed']);

            if ($anomalyCount > 0) {
                $this->warn("  Đợt #{$period->id} \"{$period->name}\": {$anomalyCount} hồ sơ bất thường bị bỏ qua (xem log).");
            }

            return $reservations->whereIn('id', $expiredIds);
        });

        foreach ($expiredReservations as $reservation) {
            if ($reservation->candidate?->email) {
                $notifier->notifyEmailOnly(
                    $reservation->candidate->email,
                    $reservation->candidate->full_name,
                    'Hồ sơ giữ chỗ KTX đã hết hiệu lực',
                    'Hồ sơ giữ chỗ KTX của bạn đã hết hiệu lực do quá hạn xác nhận nhập học.',
                );
            }
        }

        $this->info("  Đợt #{$period->id} \"{$period->name}\": {$expiredReservations->count()} hồ sơ chuyển expired, đã đóng đợt.");
    }
}
