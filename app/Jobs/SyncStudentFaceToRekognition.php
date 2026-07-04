<?php

namespace App\Jobs;

use App\Models\Student;
use App\Services\AwsFaceService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Chạy đồng bộ (không dùng queue) — đồ án quy mô nhỏ, không cần quản lý
 * `queue:work` chạy nền hay bảng jobs. Toàn bộ lỗi AWS được bắt lại ở đây
 * để việc lưu avatar sinh viên không bao giờ fail vì Rekognition lỗi/chậm.
 */
class SyncStudentFaceToRekognition
{
    use Dispatchable;

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
                'error' => $e->getMessage(),
            ]);
        }
    }
}
