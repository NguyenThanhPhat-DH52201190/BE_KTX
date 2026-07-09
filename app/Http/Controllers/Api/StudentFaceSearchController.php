<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AwsFaceService;
use Aws\Rekognition\Exception\RekognitionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentFaceSearchController extends Controller
{
    public function search(Request $request, AwsFaceService $service): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
        ]);

        $absolutePath = $request->file('image')->getRealPath();

        try {
            $matches = $service->searchByImage($absolutePath, 5);
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() === 'InvalidParameterException') {
                return response()->json([
                    'message' => 'Không phát hiện khuôn mặt trong ảnh.',
                ], 422);
            }

            Log::error('StudentFaceSearchController: AWS Rekognition error', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Dịch vụ nhận diện khuôn mặt hiện không khả dụng, vui lòng thử lại sau.',
            ], 503);
        }

        if (empty($matches)) {
            return response()->json(['results' => []]);
        }

        // Chỉ giữ lại sinh viên nằm trong đúng danh sách mà trang "Quản lý lưu trú" liệt kê
        // khi filter trạng thái = "Tất cả" (xem RegistrationController::index()).
        $validStudentIds = $this->getStudentIdsListedInOccupancyManagement();
        $matches = array_values(array_filter(
            $matches,
            fn (array $match) => in_array((int) $match['external_image_id'], $validStudentIds, true),
        ));

        if (empty($matches)) {
            return response()->json(['results' => []]);
        }

        $studentIds = array_map(fn (array $match) => (int) $match['external_image_id'], $matches);
        $similarityByStudentId = array_column($matches, 'similarity', 'external_image_id');

        $students = Student::query()
            ->whereIn('id', $studentIds)
            ->with(['registrations' => function ($query) {
                $query->whereHas('occupancy')
                    ->with(['occupancy.room.floor', 'occupancy.bed', 'occupancy.pendingCheckoutRequest'])
                    ->latest('id');
            }])
            ->get()
            ->keyBy('id');

        $results = collect($studentIds)
            ->map(function (int $studentId) use ($students, $similarityByStudentId) {
                $student = $students->get($studentId);

                if (! $student) {
                    return null;
                }

                $latestReg = $student->registrations
                    ->filter(fn ($r) => $r->occupancy !== null)
                    ->sortByDesc('id')
                    ->first();

                $occupancy = $latestReg?->occupancy;
                $room      = $occupancy?->room;
                $floor     = $room?->floor;

                $avatarUrl = $student->avatar
                    ? $this->resolveImageUrl((string) $student->avatar)
                    : null;

                return [
                    'student' => [
                        'id'               => $student->id,
                        'full_name'        => $student->full_name,
                        'student_code'     => $student->student_code,
                        'avatar_url'       => $avatarUrl,
                        'room_number'      => $room?->room_number ? (string) $room->room_number : null,
                        'building_code'    => $floor?->building_code ?? null,
                        'faculty'          => $student->faculty,
                        'current_year'     => $student->current_year,
                        'occupancy_status' => $this->normalizeOccupancyStatus($occupancy),
                        'occupancy_id'     => $occupancy?->id,
                        'bed_number'       => $occupancy?->bed?->bed_number,
                        'check_out_date'   => $occupancy?->check_out_date,
                        'registration_id'  => $latestReg?->id,
                    ],
                    'similarity' => round($similarityByStudentId[$studentId] ?? 0, 1),
                ];
            })
            ->filter()
            ->sortByDesc('similarity')
            ->values();

        return response()->json(['results' => $results]);
    }

    /**
     * Danh sách student_id được trang "Quản lý lưu trú" liệt kê khi filter trạng thái = "Tất cả".
     * Tái sử dụng nguyên vẹn RegistrationController::index() + mapOccupancyStatus() (nguồn chân lý
     * duy nhất cho "ai đang được tính là có lưu trú") để không lệch với các quy tắc nghiệp vụ
     * (vd. đã xếp phòng nhưng chưa thanh toán/chưa chọn giường thì KHÔNG được tính).
     * Điều kiện lọc dưới đây transcribe y hệt OccupancyManagementPage.tsx (createOccupancyRowsFromApi).
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

    private function normalizeOccupancyStatus(mixed $occupancy): ?string
    {
        if (! $occupancy) {
            return null;
        }

        if ($occupancy->pendingCheckoutRequest) {
            return 'CHECKOUT_REQUESTED';
        }

        return match ($occupancy->status) {
            'ACTIVE', 'ROOM_CONFIRMED' => 'ACTIVE',
            'COMPLETED'                => 'CHECKED_OUT',
            'TERMINATED'               => 'FORCED_CHECKOUT',
            default                    => null,
        };
    }

    private function resolveImageUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $cleanPath    = ltrim($path, '/');
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        return $isProduction
            ? url('/api/storage/' . $cleanPath)
            : url('/storage/' . $cleanPath);
    }
}
