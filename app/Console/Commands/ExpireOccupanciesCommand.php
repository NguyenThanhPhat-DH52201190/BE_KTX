<?php

namespace App\Console\Commands;

use App\Models\Bed;
use App\Models\Notification;
use App\Models\Occupancy;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ExpireOccupanciesCommand extends Command
{
    protected $signature   = 'occupancies:expire {--dry-run : Chạy thử, không ghi database hay gửi email}';
    protected $description = 'Tự động kết thúc lưu trú hết hạn: occupancy → COMPLETED, giường → EMPTY';

    private bool $isDry;
    private int  $expired = 0;

    public function handle(): int
    {
        $this->isDry = (bool) $this->option('dry-run');
        $today       = Carbon::today();

        $this->info(sprintf(
            '[occupancies:expire] %s (ngày: %s)',
            $this->isDry ? 'DRY RUN — không ghi dữ liệu.' : 'Bắt đầu...',
            $today->toDateString(),
        ));

        Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_out_date')
            ->whereDate('check_out_date', '<', $today)
            ->with(['student', 'bed'])
            ->each(fn (Occupancy $occ) => $this->expireOccupancy($occ));

        $this->info("Đã kết thúc lưu trú: {$this->expired}");

        return self::SUCCESS;
    }

    private function expireOccupancy(Occupancy $occupancy): void
    {
        $student = $occupancy->student;

        if (! $this->isDry) {
            DB::transaction(function () use ($occupancy) {
                $occupancy->update(['status' => 'COMPLETED']);

                if ($occupancy->bed_id) {
                    Bed::where('id', $occupancy->bed_id)->update(['status' => 'EMPTY']);
                }
            });

            if ($student) {
                $this->sendExpiredNotification($occupancy, $student);
            }
        }

        $label = $student
            ? "{$student->full_name} ({$student->student_code})"
            : "occupancy #{$occupancy->id}";
        $this->line("  [EXPIRED] {$label} — hết hạn {$occupancy->check_out_date}");
        $this->expired++;
    }

    private function sendExpiredNotification(Occupancy $occupancy, mixed $student): void
    {
        try {
            $notification = Notification::create([
                'student_id'  => $student->id,
                'title'       => 'Lưu trú đã hết hạn',
                'content'     => "Lưu trú của bạn tại KTX đã kết thúc vào ngày {$occupancy->check_out_date}. "
                    . 'Nếu muốn ở tiếp, vui lòng đăng ký mới trong đợt đăng ký tiếp theo.',
                'type'        => 'occupancy_expired',
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
                $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px">
  <h2 style="color:#374151">Thông báo hết hạn lưu trú — KTX</h2>
  <p>Kính gửi <strong>{$student->full_name}</strong>,</p>
  <p>Lưu trú của bạn tại KTX đã kết thúc vào ngày <strong>{$occupancy->check_out_date}</strong>.</p>
  <p>Nếu bạn muốn tiếp tục ở lại, vui lòng đăng nhập hệ thống KTX và đăng ký mới trong đợt tiếp theo.</p>
  <p style="color:#6b7280;font-size:12px">Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
</div>
HTML;
                Mail::send([], [], function ($message) use ($student, $body) {
                    $message->to($student->email, $student->full_name)
                        ->subject('KTX — Lưu trú của bạn đã hết hạn')
                        ->html($body);
                });
            }
        } catch (\Throwable $e) {
            Log::error("[ExpireOccupancies] Gửi thông báo thất bại student #{$student->id}: " . $e->getMessage());
        }
    }
}
