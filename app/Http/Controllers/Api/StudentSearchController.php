<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q     = trim((string) ($request->query('q') ?? ''));
        $limit = min((int) ($request->query('limit', 8)), 20);

        if (strlen($q) === 0) {
            return response()->json([]);
        }

        $results = Student::query()
            ->whereExists(function ($query) {
                // Chỉ tính occupancy còn ý nghĩa cho trang "Quản lý lưu trú" — khớp đúng các
                // trạng thái đang lọc được ở đó (Đang lưu trú/Yêu cầu thôi ở/Đã thôi ở/Buộc
                // thôi ở). Loại CANCELLED (chưa từng ở thật, hồ sơ bị hủy giữa chừng) và
                // PENDING_PAYMENT/PROPOSED (chưa có chỗ thật) — không thuộc trang này.
                $query->select(DB::raw(1))
                    ->from('occupancy')
                    ->whereColumn('occupancy.student_id', 'students.id')
                    ->whereIn('occupancy.status', ['ACTIVE', 'COMPLETED', 'TERMINATED']);
            })
            ->where(function ($query) use ($q) {
                $query->where('full_name', 'like', "%{$q}%")
                      ->orWhere('student_code', 'like', "%{$q}%");
            })
            ->with(['registrations' => function ($query) {
                $query->whereHas('occupancy')
                      ->with(['occupancy.room.floor', 'occupancy.bed', 'occupancy.pendingCheckoutRequest'])
                      ->latest('id');
            }])
            ->limit($limit)
            ->get()
            ->map(function (Student $student) {
                // Lấy đăng ký mới nhất có occupancy
                $latestReg = $student->registrations
                    ->filter(fn ($r) => $r->occupancy !== null)
                    ->sortByDesc('id')
                    ->first();

                $occupancy = $latestReg?->occupancy;
                $room      = $occupancy?->room;
                $floor     = $room?->floor;

                // Sinh viên nguồn giữ chỗ tân sinh viên có thể chưa có Student.avatar (dữ liệu
                // cũ trước fix) — fallback sang avatar_url của Registration mới nhất.
                $avatarRaw = $student->avatar ?? $latestReg?->avatar_url;
                $avatarUrl = $avatarRaw ? $this->resolveImageUrl((string) $avatarRaw) : null;

                return [
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
                ];
            });

        return response()->json($results);
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
            'ACTIVE'      => 'ACTIVE',
            'COMPLETED'   => 'CHECKED_OUT',
            'TERMINATED'  => 'FORCED_CHECKOUT',
            default       => null,
        };
    }

    private function resolveImageUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Một số bản ghi (vd. Registration convert từ hồ sơ giữ chỗ tân sinh viên) đã có sẵn
        // prefix storage trong path lưu — phải strip trước, tránh double-prefix khiến ảnh 404.
        $cleanPath    = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        return $isProduction
            ? url('/api/storage/' . $cleanPath)
            : url('/storage/' . $cleanPath);
    }
}
