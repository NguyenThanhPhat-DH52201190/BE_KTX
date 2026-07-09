<?php

namespace App\Console\Commands;

use App\Models\SystemAnnouncement;
use App\Services\SystemAnnouncementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendScheduledSystemAnnouncementsCommand extends Command
{
    protected $signature = 'system-announcements:send-scheduled';

    protected $description = 'Send due scheduled system announcements';

    public function handle(SystemAnnouncementService $service): int
    {
        $dueIds = SystemAnnouncement::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->pluck('id');

        foreach ($dueIds as $id) {
            try {
                DB::transaction(function () use ($id, $service) {
                    // Khóa dòng + kiểm tra lại status ngay tại thời điểm xử lý — giữa lúc query
                    // danh sách due ở trên và lúc thực sự xử lý từng bản ghi, admin có thể đã kịp
                    // sửa/hủy hẹn giờ (chuyển về draft). lockForUpdate() đảm bảo không đọc phải dữ
                    // liệu đang bị admin ghi đè cùng lúc, và việc re-check status ngay sau khi khóa
                    // đảm bảo bỏ qua nếu bản ghi không còn đủ điều kiện gửi nữa.
                    $announcement = SystemAnnouncement::where('id', $id)->lockForUpdate()->first();

                    if (! $announcement
                        || $announcement->status !== 'scheduled'
                        || ! $announcement->scheduled_at
                        || $announcement->scheduled_at->isFuture()) {
                        $this->line("Bỏ qua #{$id}: không còn đủ điều kiện gửi (có thể admin đã sửa/hủy hẹn giờ).");
                        return;
                    }

                    $service->send($announcement);
                    $this->line("Sent system announcement #{$announcement->id}");
                });
            } catch (\Throwable $e) {
                SystemAnnouncement::where('id', $id)->update(['status' => 'failed']);
                Log::error('Gửi thông báo hẹn giờ thất bại', [
                    'announcement_id' => $id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
