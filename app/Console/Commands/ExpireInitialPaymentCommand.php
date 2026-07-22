<?php

namespace App\Console\Commands;

use App\Models\Bed;
use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use App\Services\StudentNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireInitialPaymentCommand extends Command
{
    protected $signature   = 'initial-payment:expire {--dry-run : Chạy thử, không ghi database hay gửi email}';
    protected $description = 'Huỷ giữ chỗ của sinh viên đã chọn giường nhưng không đóng tiền lần đầu đúng hạn (initial_payment_due_days)';

    private bool $isDry;
    private int  $expired = 0;

    public function handle(StudentNotificationService $notifier): int
    {
        $this->isDry = (bool) $this->option('dry-run');
        $today       = Carbon::today();

        $this->info(sprintf(
            '[initial-payment:expire] %s (ngày: %s)',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu...',
            $today->toDateString(),
        ));

        // Occupancy đã chọn giường (PENDING_PAYMENT) nhưng hóa đơn tháng đầu
        // (tạo lúc chọn giường, hạn = ngày chọn giường + initial_payment_due_days)
        // vẫn chưa thanh toán và đã quá hạn.
        $occupancies = Occupancy::where('status', 'PENDING_PAYMENT')
            ->whereNotNull('check_in_date')
            ->with(['student', 'registration', 'room.floor', 'roomFeeBills'])
            ->get();

        foreach ($occupancies as $occupancy) {
            $firstBill = $this->firstBill($occupancy);

            if (! $firstBill || $firstBill->status !== 'unpaid' || Carbon::parse($firstBill->due_date)->gte($today)) {
                continue;
            }

            $this->expireOne($occupancy, $notifier);
        }

        $this->info("Đã huỷ: {$this->expired}");

        return self::SUCCESS;
    }

    /**
     * Hóa đơn tháng check-in — đúng hóa đơn được tạo bởi
     * RoomFeeBillingService::createInitialBill() lúc sinh viên chọn giường.
     */
    private function firstBill(Occupancy $occupancy): ?RoomFeeBill
    {
        $checkIn = Carbon::parse($occupancy->check_in_date);

        return $occupancy->roomFeeBills
            ->where('month', $checkIn->month)
            ->where('year', $checkIn->year)
            ->first();
    }

    private function expireOne(Occupancy $occupancy, StudentNotificationService $notifier): void
    {
        $student      = $occupancy->student;
        $registration = $occupancy->registration;
        $roomCode     = $this->roomCode($occupancy);
        $label        = $student ? "{$student->full_name} ({$student->student_code})" : "occupancy #{$occupancy->id}";

        if ($this->isDry) {
            $this->line("  [DRY] {$label} — phòng {$roomCode} — sẽ bị huỷ do quá hạn đóng tiền lần đầu");
            $this->expired++;
            return;
        }

        DB::transaction(function () use ($occupancy, $registration) {
            if ($occupancy->bed_id) {
                Bed::where('id', $occupancy->bed_id)
                    ->where('status', '!=', 'maintenance')
                    ->update(['status' => 'active']);
            }

            // Lưu trú chưa từng bắt đầu (chưa ACTIVE) nên không giữ lại công nợ.
            RoomFeeBill::where('occupancy_id', $occupancy->id)
                ->where('status', 'unpaid')
                ->delete();

            $occupancy->update([
                'status'         => 'CANCELLED',
                'reason'         => 'INITIAL_PAYMENT_EXPIRED',
                'check_out_date' => now()->toDateString(),
            ]);

            // Hồ sơ chuyển sang rejected để sinh viên có thể đăng ký lại
            // (status='approved' sẽ chặn eligibility() cho các đợt sau).
            if ($registration) {
                $registration->update([
                    'status'           => 'rejected',
                    'rejection_reason' => 'Quá hạn đóng tiền lần đầu sau khi chọn giường. Hồ sơ và vị trí giường tự động bị huỷ, vui lòng đăng ký lại nếu vẫn có nhu cầu ở KTX.',
                ]);
            }
        });

        if ($student) {
            $notifier->notifyStudent(
                $student,
                'Hồ sơ đăng ký nội trú đã bị huỷ do quá hạn đóng tiền lần đầu',
                "Bạn đã chọn Phòng {$roomCode} nhưng chưa hoàn tất thanh toán hóa đơn tháng đầu trong thời hạn quy định. "
                    . 'Hồ sơ đăng ký nội trú và vị trí giường của bạn đã bị huỷ. '
                    . 'Nếu vẫn có nhu cầu ở KTX, vui lòng đăng ký lại trong đợt đăng ký gần nhất.',
                'initial_payment_expired',
                $registration?->id,
                queue: true,
            );
        }

        $this->line("  [EXPIRED] {$label} — phòng {$roomCode}");
        $this->expired++;
    }

    private function roomCode(Occupancy $occupancy): string
    {
        $room = $occupancy->room;
        if (! $room) {
            return '(không rõ)';
        }

        return ($room->floor?->building_code ?? '') . $room->room_number;
    }
}
