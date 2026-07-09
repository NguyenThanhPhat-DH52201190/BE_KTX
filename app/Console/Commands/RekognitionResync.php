<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\RegistrationController;
use App\Models\Student;
use App\Services\AwsFaceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RekognitionResync extends Command
{
    protected $signature = 'rekognition:resync
                            {--force : Bỏ qua xác nhận, chạy thẳng}';

    protected $description = 'Đồng bộ lại AWS Rekognition Collection: xóa toàn bộ face hiện có, '
        . 'index lại đúng những sinh viên đang có bản ghi lưu trú hợp lệ (theo đúng điều kiện lọc '
        . 'dùng cho tìm kiếm khuôn mặt)';

    public function handle(AwsFaceService $service): int
    {
        if (! $this->option('force')) {
            $this->warn('⚠  Lệnh này sẽ XÓA TOÀN BỘ face hiện có trong AWS Rekognition Collection, sau đó index lại từ đầu.');
            if (! $this->confirm('Bạn có chắc muốn tiếp tục không?')) {
                $this->info('Đã hủy.');
                return self::SUCCESS;
            }
        }

        $service->createCollectionIfNotExists();

        // ---------- BƯỚC 1: Xóa toàn bộ face hiện có (nguồn chân lý là AWS, không dựa vào students.aws_face_id) ----------
        $this->info('Đang lấy danh sách face hiện có trong Collection...');
        $existingFaces = $service->listFaces();
        $this->info('Tìm thấy ' . count($existingFaces) . ' face hiện có.');

        $deletedCount = 0;
        if (count($existingFaces) > 0) {
            $faceIds = array_map(fn (array $f) => $f['FaceId'], $existingFaces);
            $this->line('Đang xóa ' . count($faceIds) . ' face...');
            $deleted = $service->deleteFaces($faceIds);
            $deletedCount = count($deleted);
            $this->info("Đã xóa {$deletedCount}/" . count($faceIds) . ' face.');
        }

        // Dọn sạch aws_face_id cũ trên toàn bộ students — Collection vừa bị xóa sạch nên
        // mọi giá trị aws_face_id hiện tại (nếu còn) đều đã không còn hợp lệ.
        Student::whereNotNull('aws_face_id')->update(['aws_face_id' => null]);

        $this->newLine();

        // ---------- BƯỚC 2: Xác định sinh viên đang có lưu trú hợp lệ (tái dùng đúng điều kiện lọc đã có) ----------
        $this->info('Đang xác định sinh viên có bản ghi lưu trú hợp lệ...');
        $validStudentIds = $this->getStudentIdsListedInOccupancyManagement();
        $this->info('Số sinh viên hợp lệ: ' . count($validStudentIds));

        if (empty($validStudentIds)) {
            $this->info('Không có sinh viên nào đang có lưu trú hợp lệ — Collection sẽ giữ nguyên trạng thái rỗng.');
            $this->printSummary($deletedCount, 0, [], []);
            return self::SUCCESS;
        }

        // ---------- BƯỚC 3: Index lại face cho từng sinh viên hợp lệ có avatar ----------
        $students = Student::whereIn('id', $validStudentIds)->whereNotNull('avatar')->get();
        $this->info('Số sinh viên hợp lệ có avatar để index: ' . $students->count());

        $successIds = [];
        $failedIds = [];

        $bar = $this->output->createProgressBar($students->count());
        $bar->start();

        foreach ($students as $student) {
            try {
                $faceId = $service->indexFace($student->avatar, $student->id);
                if ($faceId) {
                    $student->update(['aws_face_id' => $faceId]);
                    $successIds[] = $student->id;
                } else {
                    $failedIds[] = $student->id;
                }
            } catch (\Throwable $e) {
                $failedIds[] = $student->id;
                Log::error('rekognition:resync: index thất bại cho sinh viên', [
                    'student_id' => $student->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
            usleep(200_000); // tránh rate limit AWS khi index hàng loạt
        }

        $bar->finish();
        $this->newLine(2);

        $this->printSummary($deletedCount, count($successIds), $successIds, $failedIds);

        return self::SUCCESS;
    }

    /**
     * Danh sách student_id được trang "Quản lý lưu trú" liệt kê khi filter trạng thái = "Tất cả".
     * Copy nguyên vẹn điều kiện lọc từ StudentFaceSearchController::getStudentIdsListedInOccupancyManagement()
     * để đảm bảo tập hợp "được phép xuất hiện trong Collection" luôn khớp với tập hợp "được phép trả về khi search".
     */
    private function getStudentIdsListedInOccupancyManagement(): array
    {
        $registrations = app(RegistrationController::class)->index();

        $occupancyManagementStatuses = [
            'active', 'checkout_requested', 'completed', 'checked_out', 'terminated', 'forced_checkout',
        ];

        return collect($registrations)
            ->filter(function (array $registration) use ($occupancyManagementStatuses) {
                return ($registration['status'] ?? null) === 'approved'
                    && ! empty($registration['occupancy_id'])
                    && ! empty($registration['assigned_room_id'])
                    && ! empty($registration['assigned_bed_id'])
                    && in_array(
                        strtolower(trim((string) ($registration['occupancy_status'] ?? ''))),
                        $occupancyManagementStatuses,
                        true,
                    );
            })
            ->pluck('student_id')
            ->unique()
            ->values()
            ->all();
    }

    private function printSummary(int $deletedCount, int $successCount, array $successIds, array $failedIds): void
    {
        $this->info('=== KẾT QUẢ ĐỒNG BỘ ===');
        $this->table(['Hạng mục', 'Số lượng'], [
            ['Face đã xóa khỏi Collection', $deletedCount],
            ['Face mới đã index thành công', $successCount],
            ['Sinh viên index thất bại', count($failedIds)],
        ]);

        if (! empty($successIds)) {
            $this->line('Student ID index thành công: ' . implode(', ', $successIds));
        }
        if (! empty($failedIds)) {
            $this->warn('Student ID index thất bại: ' . implode(', ', $failedIds));
        }

        $this->newLine();
        $this->info('✅ Đồng bộ Collection hoàn tất.');
    }
}
