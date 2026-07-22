<?php

namespace App\Jobs;

use App\Models\Student;
use App\Services\AwsFaceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Chạy NỀN qua queue (database driver, xem QUEUE_CONNECTION trong .env) —
 * gọi AWS Rekognition thật (~2s/lần, độ trễ mạng cao vì region us-east-1),
 * không được chặn request nộp đơn/lưu avatar của sinh viên. aws_face_id chỉ
 * phục vụ tính năng tìm kiếm khuôn mặt sau này (StudentFaceSearchController)
 * và các lệnh đồng bộ lại (RekognitionResync/SyncStudentFaces) — không có
 * luồng nào trong lúc xử lý đơn đăng ký (auto_decision, approve/reject...)
 * đọc aws_face_id để ra quyết định ngay, nên trễ vài giây là chấp nhận được.
 */
class SyncStudentFaceToRekognition implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private int $studentId) {}

    public function handle(AwsFaceService $service): void
    {
        $student = Student::find($this->studentId);

        if (! $student || ! $student->avatar) {
            return;
        }

        try {
            if ($student->aws_face_id) {
                $service->deleteFace($student->aws_face_id);
            }

            $faceId = $service->indexFace($student->avatar, $student->id);
            $student->update(['aws_face_id' => $faceId]);
        } catch (\Throwable $e) {
            Log::error('SyncStudentFaceToRekognition: failed to sync face', [
                'student_id' => $student->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Rethrow để queue worker tính đây là lần thử thất bại và tự retry
            // theo $backoff — nếu nuốt lỗi ở đây, job coi như "thành công" dù
            // avatar chưa được index, mất luôn cơ hội thử lại.
            throw $e;
        }
    }

    /**
     * Hết số lần thử (3 lần, xem $tries) mà vẫn lỗi — log rõ ràng để không
     * mất dấu vết. Không có gì khác cần dọn (không giữ tài nguyên tạm nào).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncStudentFaceToRekognition: hết số lần thử, bỏ qua đồng bộ khuôn mặt', [
            'student_id' => $this->studentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
