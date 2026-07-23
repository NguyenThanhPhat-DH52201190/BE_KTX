<?php

namespace App\Jobs;

use App\Helpers\StorageHelper;
use App\Models\StudentPriority;
use App\Services\StudentNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lưu ảnh minh chứng diện ưu tiên vào vị trí lưu trữ cuối (Railway volume hoặc disk
 * 'public'). Được gọi qua dispatchSync() ngay trong request nộp đơn — KHÔNG chạy qua
 * queue worker, vì cần ghi vào Railway volume mà chỉ service web mới mount được.
 * Implements ShouldQueue để vẫn dùng chung được các cơ chế retry/backoff/failed() của
 * job, dù thực thi đồng bộ.
 */
class ProcessPriorityEvidenceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $registrationId,
        public int $priorityCriteriaId,
        public string $stagingDir,
        public array $stagedFilenames,
    ) {
    }

    public function handle(): void
    {
        $priority = StudentPriority::where('registration_id', $this->registrationId)
            ->where('priority_criteria_id', $this->priorityCriteriaId)
            ->first();

        if (!$priority) {
            $this->cleanupStaging();
            return;
        }

        $path = 'students/priority_evidence';

        foreach ($this->stagedFilenames as $index => $stagedName) {
            $stagedPath = $this->stagingDir . '/' . $stagedName;
            if (!Storage::disk('local')->exists($stagedPath)) {
                continue;
            }

            $extension = pathinfo($stagedName, PATHINFO_EXTENSION);
            $filename = 'evidence_' . $this->priorityCriteriaId . '_' . time() . '_' . uniqid() . '_' . $index . '.' . $extension;

            if (StorageHelper::isRailwayWithVolume()) {
                $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
                $fullDir = $volumePath . '/' . $path;
                if (!file_exists($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
                copy(Storage::disk('local')->path($stagedPath), $fullDir . '/' . $filename);
                $fileUrl = $path . '/' . $filename;
            } else {
                $stream = Storage::disk('local')->readStream($stagedPath);
                Storage::disk('public')->put($path . '/' . $filename, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $fileUrl = $path . '/' . $filename;
            }

            $priority->evidences()->create(['file_url' => $fileUrl]);
        }

        $this->cleanupStaging();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessPriorityEvidenceJob: lưu minh chứng thất bại sau khi hết số lần thử lại', [
            'registration_id' => $this->registrationId,
            'priority_criteria_id' => $this->priorityCriteriaId,
            'error' => $exception->getMessage(),
        ]);

        $priority = StudentPriority::with('student')
            ->where('registration_id', $this->registrationId)
            ->where('priority_criteria_id', $this->priorityCriteriaId)
            ->first();

        if ($priority?->student) {
            app(StudentNotificationService::class)->notifyStudent(
                $priority->student,
                'Lỗi tải ảnh minh chứng ưu tiên',
                'Hệ thống chưa thể lưu một hoặc nhiều ảnh minh chứng cho diện ưu tiên bạn đã đăng ký. Vui lòng vào lại trang đăng ký nội trú để kiểm tra và tải lại ảnh minh chứng.',
                'priority_evidence_failed',
                $this->registrationId
            );
        }

        $this->cleanupStaging();
    }

    private function cleanupStaging(): void
    {
        Storage::disk('local')->deleteDirectory($this->stagingDir);
    }
}
