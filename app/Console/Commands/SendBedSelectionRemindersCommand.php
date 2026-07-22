<?php

namespace App\Console\Commands;

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\Occupancy;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBedSelectionRemindersCommand extends Command
{
    protected $signature   = 'bed-selection:send-reminders {--dry-run : Chạy thử, không ghi database hay gửi email}';
    protected $description = 'Nhắc sinh viên chọn giường trước 1 ngày khi hết hạn';

    private bool $isDry;
    private int  $sent = 0;

    public function handle(): int
    {
        $this->isDry = (bool) $this->option('dry-run');
        $today       = Carbon::today();
        $tomorrow    = $today->copy()->addDay();

        $this->info(sprintf(
            '[bed-selection:send-reminders] %s (ngày: %s)',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu...',
            $today->toDateString(),
        ));

        // Sinh viên có status ROOM_CONFIRMED (đã được phân phòng, chưa chọn giường)
        // và deadline chọn giường (approved_at + bed_selection_days) = ngày mai
        $occupancies = Occupancy::query()
            ->from('occupancy')
            ->where('occupancy.status', 'ROOM_CONFIRMED')
            ->join('registrations', 'registrations.id', '=', 'occupancy.registration_id')
            ->join('registration_periods', 'registration_periods.id', '=', 'registrations.registration_period_id')
            ->whereNotNull('registrations.approved_at')
            ->whereNotNull('registration_periods.bed_selection_days')
            ->where('registration_periods.bed_selection_days', '>', 0)
            ->whereRaw(
                'DATE(DATE_ADD(registrations.approved_at, INTERVAL registration_periods.bed_selection_days DAY)) = ?',
                [$tomorrow->toDateString()],
            )
            ->select('occupancy.*')
            ->with(['student', 'registration.period', 'room.floor'])
            ->get();

        $this->info("Tìm thấy {$occupancies->count()} sinh viên cần nhắc chọn giường.");

        foreach ($occupancies as $occupancy) {
            $student = $occupancy->student;
            if (! $student) {
                continue;
            }

            $deadline     = $occupancy->registration?->bedSelectionDeadline()?->toDateTimeString()
                ?? $tomorrow->copy()->setTime(17, 0, 0)->toDateTimeString();

            if ($this->isDry) {
                $this->line("  [DRY] {$student->full_name} ({$student->student_code}) — hạn chọn giường: {$this->fmt($deadline)}");
                $this->sent++;
                continue;
            }

            $this->sendReminder($occupancy, $student, $deadline);
            $this->line("  [SENT] {$student->full_name} ({$student->student_code}) — hạn: {$this->fmt($deadline)}");
            $this->sent++;
        }

        $this->info("Đã gửi: {$this->sent} nhắc nhở.");

        return self::SUCCESS;
    }

    private function sendReminder(Occupancy $occupancy, mixed $student, string $deadline): void
    {
        $roomCode = $this->roomCode($occupancy);
        $title    = 'Nhắc nhở: Hạn chọn giường còn 1 ngày';
        $content  = "Bạn đã được phân công Phòng {$roomCode} nhưng chưa chọn giường. "
                  . "Hạn chót là ngày {$this->fmt($deadline)} (còn 1 ngày). "
                  . "Vui lòng đăng nhập hệ thống KTX để hoàn tất việc chọn giường.";

        try {
            $notification = Notification::create([
                'student_id'  => $student->id,
                'title'       => $title,
                'content'     => $content,
                'type'        => 'bed_selection_reminder',
                'target_type' => 'individual',
                'send_email'  => true,
            ]);

            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $student->id,
                'is_read'         => false,
                'read_at'         => null,
            ]);

            if ($student->email) {
                $this->sendEmail($student, $roomCode, $deadline);
            }
        } catch (\Throwable $e) {
            Log::error("[BedSelectionReminders] Lỗi gửi thông báo student #{$student->id}: " . $e->getMessage());
        }
    }

    private function sendEmail(mixed $student, string $roomCode, string $deadline): void
    {
        $name        = htmlspecialchars($student->full_name ?? '');
        $deadlineFmt = $this->fmt($deadline);

        $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152">
  <h2 style="color:#dc2626;margin-top:0">Nhắc nhở: Hạn chọn giường còn 1 ngày</h2>
  <p>Kính gửi <strong>{$name}</strong> ({$student->student_code}),</p>
  <p>Bạn đã được phân công <strong>Phòng {$roomCode}</strong> tại KTX nhưng <strong>chưa hoàn tất việc chọn giường</strong>.</p>
  <p style="color:#dc2626;font-weight:bold">Hạn chót: {$deadlineFmt} (còn 1 ngày).</p>
  <p>Nếu không chọn giường trước hạn, vị trí của bạn có thể bị hủy theo quy định của KTX.</p>
  <p>Vui lòng đăng nhập hệ thống KTX và thực hiện chọn giường ngay hôm nay.</p>
  <p style="color:#6b7280;font-size:12px;margin-top:32px">Email này được gửi tự động bởi hệ thống quản lý KTX. Vui lòng không trả lời email này.</p>
</div>
HTML;

        try {
            Mail::to($student->email, $student->full_name)
                ->queue(new GenericNotificationMail('KTX — [QUAN TRỌNG] Hạn chọn giường còn 1 ngày', $body));
        } catch (\Throwable $e) {
            Log::error("[BedSelectionReminders] Gửi email thất bại student #{$student->id}: " . $e->getMessage());
        }
    }

    private function roomCode(Occupancy $occupancy): string
    {
        $room = $occupancy->room;
        if (! $room) {
            return '(không rõ)';
        }
        return ($room->floor?->building_code ?? '') . $room->room_number;
    }

    private function fmt(string $date): string
    {
        return Carbon::parse($date)->format('H:i d/m/Y');
    }
}
