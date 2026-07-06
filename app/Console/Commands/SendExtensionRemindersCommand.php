<?php

namespace App\Console\Commands;

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\OccupancyPeriod;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendExtensionRemindersCommand extends Command
{
    protected $signature   = 'extensions:send-reminders {--dry-run : Chạy thử, không ghi database hay gửi email}';
    protected $description = 'Gửi nhắc nhở gia hạn lưu trú: 1 tháng và 1 tuần trước khi hết hạn';

    private bool $isDry;
    private int  $sent = 0;

    public function handle(): int
    {
        $this->isDry = (bool) $this->option('dry-run');
        $today       = Carbon::today();

        $this->info(sprintf(
            '[extensions:send-reminders] %s (ngày: %s)',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu...',
            $today->toDateString(),
        ));

        $activePeriod = OccupancyPeriod::where('status', 'open')->first();

        // Nhắc 30 ngày trước hết hạn (tất cả)
        $this->sendRemindersForDate(
            $today->copy()->addDays(30)->toDateString(),
            30,
            $activePeriod,
        );

        // Nhắc 7 ngày trước hết hạn (chỉ những ai chưa gia hạn)
        $this->sendRemindersForDate(
            $today->copy()->addDays(7)->toDateString(),
            7,
            $activePeriod,
            onlyNotSubmitted: true,
        );

        $this->info("Nhắc nhở đã gửi: {$this->sent}");

        return self::SUCCESS;
    }

    private function sendRemindersForDate(
        string $targetDate,
        int $daysLeft,
        ?OccupancyPeriod $activePeriod,
        bool $onlyNotSubmitted = false,
    ): void {
        $query = Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_out_date')
            ->whereDate('check_out_date', $targetDate)
            ->with('student');

        if ($onlyNotSubmitted && $activePeriod) {
            $query->whereDoesntHave('extensions', fn ($q) =>
                $q->where('occupancy_period_id', $activePeriod->id)
            );
        }

        $query->each(function (Occupancy $occ) use ($daysLeft, $activePeriod) {
            $student = $occ->student;
            if (! $student) {
                return;
            }

            if (! $this->isDry) {
                $this->sendNotification($occ, $student, $daysLeft, $activePeriod);
            }

            $this->line("  [{$daysLeft}d] {$student->full_name} ({$student->student_code}) — hết hạn {$occ->check_out_date}");
            $this->sent++;
        });
    }

    private function sendNotification(Occupancy $occupancy, mixed $student, int $daysLeft, ?OccupancyPeriod $period): void
    {
        $periodInfo = $period
            ? " Đợt gia hạn đang mở: {$period->name} (hạn nộp: "
                . ($period->end_date ? $period->end_date->format('d/m/Y') : 'không giới hạn') . ').'
            : '';

        $title = $daysLeft >= 30
            ? 'Nhắc nhở: Lưu trú sắp hết hạn (còn 30 ngày)'
            : 'Nhắc nhở cuối: Lưu trú hết hạn sau 7 ngày — chưa gia hạn';

        $content = "Lưu trú của bạn sẽ hết hạn vào ngày {$occupancy->check_out_date} (còn {$daysLeft} ngày)."
            . $periodInfo
            . ' Nếu muốn ở tiếp, vui lòng gia hạn trên hệ thống trước hạn chót.';

        try {
            $notification = Notification::create([
                'student_id'  => $student->id,
                'title'       => $title,
                'content'     => $content,
                'type'        => 'extension_reminder',
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
                $this->sendEmail($student, $occupancy, $daysLeft, $period);
            }
        } catch (\Throwable $e) {
            Log::error("[ExtensionReminders] Lỗi gửi thông báo student #{$student->id}: " . $e->getMessage());
        }
    }

    private function sendEmail(mixed $student, Occupancy $occupancy, int $daysLeft, ?OccupancyPeriod $period): void
    {
        $alertColor = $daysLeft <= 7 ? '#dc2626' : '#d97706';
        $periodHtml = $period
            ? "<p>📋 <strong>Đợt gia hạn đang mở:</strong> {$period->name} — hạn nộp đơn: <strong>"
                . ($period->end_date ? $period->end_date->format('d/m/Y') : 'không giới hạn')
                . '</strong></p>'
            : '<p style="color:#6b7280">Hiện chưa có đợt gia hạn nào được mở. Hệ thống sẽ thông báo khi có.</p>';

        $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px">
  <h2 style="color:{$alertColor}">Nhắc nhở: Lưu trú sắp hết hạn</h2>
  <p>Kính gửi <strong>{$student->full_name}</strong> ({$student->student_code}),</p>
  <p>Lưu trú của bạn tại KTX sẽ <strong>hết hạn vào ngày {$occupancy->check_out_date}</strong> (còn <strong>{$daysLeft} ngày</strong>).</p>
  {$periodHtml}
  <p>Nếu bạn muốn tiếp tục ở lại, vui lòng đăng nhập hệ thống KTX và gửi yêu cầu gia hạn trước hạn chót.</p>
  <p>Nếu không gia hạn, lưu trú sẽ tự động kết thúc và giường sẽ được giải phóng.</p>
  <p style="color:#6b7280;font-size:12px">Email này được gửi tự động. Vui lòng không trả lời email này.</p>
</div>
HTML;

        $subject = $daysLeft <= 7
            ? 'KTX — [QUAN TRỌNG] Lưu trú hết hạn sau 7 ngày, chưa gia hạn'
            : 'KTX — Nhắc nhở gia hạn lưu trú (còn 30 ngày)';

        try {
            Mail::to($student->email, $student->full_name)->queue(new GenericNotificationMail($subject, $body));
        } catch (\Throwable $e) {
            Log::error("[ExtensionReminders] Gửi email thất bại student #{$student->id}: " . $e->getMessage());
        }
    }
}
