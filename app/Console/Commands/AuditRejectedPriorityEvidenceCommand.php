<?php

namespace App\Console\Commands;

use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\Registration;
use Illuminate\Console\Command;

/**
 * Rà dữ liệu cũ: hồ sơ có minh chứng ưu tiên bị rejected nhưng DormReservation/Registration
 * chưa cascade sang status=rejected (bug đã sửa — trước đây các hồ sơ này vẫn có thể được
 * xếp hạng/đề xuất duyệt). Mặc định CHỈ BÁO CÁO số lượng, không tự sửa dữ liệu.
 *
 * --fix: backfill status=rejected cho đúng những hồ sơ AN TOÀN để tự động sửa (theo đúng
 *        điều kiện cascade đang dùng khi admin từ chối minh chứng): DormReservation chưa
 *        converted/cancelled/expired; Registration còn 'submitted' và chưa có Occupancy
 *        ACTIVE. Hồ sơ đã converted/approved/active KHÔNG bao giờ bị đụng tới, kể cả với
 *        --fix — luôn chỉ liệt kê để admin xử lý tay.
 */
class AuditRejectedPriorityEvidenceCommand extends Command
{
    protected $signature = 'priority-evidence:audit {--fix : Backfill status=rejected cho các hồ sơ an toàn để tự động sửa}';

    protected $description = 'Rà DormReservation/Registration có minh chứng ưu tiên rejected nhưng chưa cascade sang status=rejected';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $this->auditDormReservations($fix);
        $this->newLine();
        $this->auditRegistrations($fix);

        return self::SUCCESS;
    }

    private function auditDormReservations(bool $fix): void
    {
        $reservations = DormReservation::whereHas('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->whereNotIn('status', ['rejected', 'converted', 'cancelled', 'expired'])
            ->get();

        $this->info("[DormReservation] {$reservations->count()} hồ sơ có minh chứng ưu tiên rejected nhưng chưa chuyển sang status=rejected:");

        foreach ($reservations as $reservation) {
            $this->line("  - #{$reservation->id} ({$reservation->reservation_code}) status hiện tại: {$reservation->status}");
        }

        if (!$fix || $reservations->isEmpty()) {
            return;
        }

        // Mọi hồ sơ lọt qua whereNotIn ở trên đều đã loại converted/cancelled/expired nên
        // an toàn để backfill toàn bộ — đúng điều kiện cascade dùng ở
        // ReservationPriorityController::reject().
        foreach ($reservations as $reservation) {
            $reservation->update([
                'status'           => 'rejected',
                'rejection_reason' => 'Minh chứng ưu tiên không hợp lệ.',
            ]);
        }

        $this->info("  => Đã backfill {$reservations->count()} hồ sơ sang status=rejected.");
    }

    private function auditRegistrations(bool $fix): void
    {
        $registrations = Registration::whereHas('studentPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->get();

        $this->info("[Registration] {$registrations->count()} hồ sơ có minh chứng ưu tiên rejected nhưng chưa chuyển sang status=rejected:");

        $eligible = collect();
        foreach ($registrations as $registration) {
            $hasActiveOccupancy = Occupancy::where('registration_id', $registration->id)
                ->where('status', 'ACTIVE')
                ->exists();

            $canAutoFix = $registration->status === 'submitted' && !$hasActiveOccupancy;
            $note = $canAutoFix ? '' : ' [CẦN ADMIN XỬ LÝ TAY — đã approved/đang lưu trú ACTIVE, không tự sửa]';

            $this->line("  - #{$registration->id} status hiện tại: {$registration->status}{$note}");

            if ($canAutoFix) {
                $eligible->push($registration);
            }
        }

        if (!$fix || $eligible->isEmpty()) {
            return;
        }

        foreach ($eligible as $registration) {
            $registration->update([
                'status'               => 'rejected',
                'rejection_reason'     => 'Minh chứng ưu tiên không hợp lệ.',
                'auto_decision'        => null,
                'auto_decision_reason' => null,
            ]);
        }

        $this->info("  => Đã backfill {$eligible->count()}/{$registrations->count()} hồ sơ đủ điều kiện an toàn sang status=rejected.");
    }
}
