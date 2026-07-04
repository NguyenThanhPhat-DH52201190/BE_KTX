<?php

namespace App\Observers;

use App\Jobs\SyncStudentFaceToRekognition;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

class StudentObserver
{
    public function created(Student $student): void
    {
        if ($student->avatar) {
            $this->syncFace($student);
        }
    }

    public function updated(Student $student): void
    {
        if ($student->wasChanged('avatar') && $student->avatar) {
            $this->syncFace($student);
        }
    }

    /**
     * Đồng bộ face chạy đồng bộ ngay trong request lưu avatar. Bọc try-catch
     * ở đây (thêm một lớp bảo vệ ngoài lớp đã có trong Job) để lỗi AWS không
     * bao giờ làm fail việc tạo/cập nhật sinh viên.
     */
    private function syncFace(Student $student): void
    {
        try {
            SyncStudentFaceToRekognition::dispatch($student->id);
        } catch (\Throwable $e) {
            Log::error('StudentObserver: failed to sync student face', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
