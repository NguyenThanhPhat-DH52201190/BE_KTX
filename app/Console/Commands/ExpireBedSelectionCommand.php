<?php

namespace App\Console\Commands;

use App\Models\Bed;
use App\Models\Occupancy;
use App\Services\StudentNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireBedSelectionCommand extends Command
{
    protected $signature   = 'bed-selection:expire {--dry-run : Chạy thử, không ghi database hay gửi email}';
    protected $description = 'Huỷ hồ sơ của sinh viên đã được phân phòng nhưng quá hạn chọn giường (không tự động gán giường)';

    private bool $isDry;
    private int  $expired = 0;

    public function handle(StudentNotificationService $notifier): int
    {
        $this->isDry = (bool) $this->option('dry-run');
        $today       = Carbon::today();

        $this->info(sprintf(
            '[bed-selection:expire] %s (ngày: %s)',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu...',
            $today->toDateString(),
        ));

        // Sinh viên có status ROOM_CONFIRMED (đã được phân phòng, chưa chọn giường)
        // và deadline chọn giường (approved_at + bed_selection_days) đã trôi qua.
        $occupancies = Occupancy::query()
            ->from('occupancy')
            ->where('occupancy.status', 'ROOM_CONFIRMED')
            ->join('registrations', 'registrations.id', '=', 'occupancy.registration_id')
            ->join('registration_periods', 'registration_periods.id', '=', 'registrations.registration_period_id')
            ->whereNotNull('registrations.approved_at')
            ->whereNotNull('registration_periods.bed_selection_days')
            ->where('registration_periods.bed_selection_days', '>', 0)
            ->whereRaw(
                'DATE(DATE_ADD(registrations.approved_at, INTERVAL registration_periods.bed_selection_days DAY)) < ?',
                [$today->toDateString()],
            )
            ->select('occupancy.*')
            ->with(['student', 'registration', 'room.floor'])
            ->get();

        $this->info("Tìm thấy {$occupancies->count()} hồ sơ quá hạn chọn giường.");

        foreach ($occupancies as $occupancy) {
            $this->expireOne($occupancy, $notifier);
        }

        $this->info("Đã huỷ: {$this->expired}");

        return self::SUCCESS;
    }

    private function expireOne(Occupancy $occupancy, StudentNotificationService $notifier): void
    {
        $student      = $occupancy->student;
        $registration = $occupancy->registration;
        $roomCode     = $this->roomCode($occupancy);
        $label        = $student ? "{$student->full_name} ({$student->student_code})" : "occupancy #{$occupancy->id}";

        if ($this->isDry) {
            $this->line("  [DRY] {$label} — phòng {$roomCode} — sẽ bị huỷ do quá hạn chọn giường");
            $this->expired++;
            return;
        }

        DB::transaction(function () use ($occupancy, $registration) {
            // Giường (nếu có, ví dụ do bị admin từ chối trước đó) được trả lại — không giữ chỗ.
            if ($occupancy->bed_id) {
                Bed::where('id', $occupancy->bed_id)
                    ->where('status', '!=', 'maintenance')
                    ->update(['status' => 'active']);
            }

            $occupancy->update([
                'status'         => 'CANCELLED',
                'reason'         => 'BED_SELECTION_EXPIRED',
                'check_out_date' => now()->toDateString(),
            ]);

            // Hồ sơ chuyển sang rejected để sinh viên có thể đăng ký lại
            // (status='approved' sẽ chặn eligibility() cho các đợt sau).
            if ($registration) {
                $registration->update([
                    'status'           => 'rejected',
                    'rejection_reason' => 'Quá hạn chọn giường sau khi được phân phòng. Hồ sơ tự động bị huỷ, vui lòng đăng ký lại nếu vẫn có nhu cầu ở KTX.',
                ]);
            }
        });

        if ($student) {
            $notifier->notifyStudent(
                $student,
                'Hồ sơ đăng ký nội trú đã bị huỷ do quá hạn chọn giường',
                "Bạn đã được phân công Phòng {$roomCode} nhưng không hoàn tất chọn giường trong thời hạn quy định. "
                    . 'Hồ sơ đăng ký nội trú của bạn đã bị huỷ, giường không được tự động giữ lại. '
                    . 'Nếu vẫn có nhu cầu ở KTX, vui lòng đăng ký lại trong đợt đăng ký gần nhất.',
                'bed_selection_expired',
                $registration?->id,
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
