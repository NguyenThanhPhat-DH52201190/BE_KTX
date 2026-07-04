<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\AwsFaceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncStudentFaces extends Command
{
    protected $signature = 'students:sync-faces';

    protected $description = 'Đồng bộ avatar của toàn bộ sinh viên hiện có vào AWS Rekognition Collection';

    public function handle(AwsFaceService $service): int
    {
        $service->createCollectionIfNotExists();

        $students = Student::whereNotNull('avatar')->get();

        if ($students->isEmpty()) {
            $this->info('Không có sinh viên nào có avatar để đồng bộ.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($students->count());
        $bar->start();

        $successCount = 0;
        $failCount = 0;

        foreach ($students as $student) {
            try {
                if ($student->aws_face_id) {
                    $service->deleteFace($student->aws_face_id);
                }

                $faceId = $service->indexFace($student->avatar, $student->id);
                $student->update(['aws_face_id' => $faceId]);

                $faceId ? $successCount++ : $failCount++;
            } catch (\Throwable $e) {
                $failCount++;
                Log::error('students:sync-faces: failed for student', [
                    'student_id' => $student->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
            usleep(200_000); // tránh rate limit AWS khi đồng bộ hàng loạt
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Hoàn tất: {$successCount} thành công, {$failCount} thất bại / {$students->count()} sinh viên.");

        return self::SUCCESS;
    }
}
