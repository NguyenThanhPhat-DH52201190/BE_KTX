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
     * SyncStudentFaceToRekognition chạy NỀN qua queue (ShouldQueue) — dispatch()
     * chỉ ghi 1 dòng vào bảng jobs, không gọi AWS ngay nên không chặn request
     * lưu avatar. try-catch ở đây chỉ để phòng lỗi lúc GHI job vào queue (DB
     * lỗi, v.v.) — lỗi AWS thật sự (indexFace) được xử lý/log riêng trong job.
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
