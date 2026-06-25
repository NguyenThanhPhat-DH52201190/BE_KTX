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
                $query->select(DB::raw(1))
                    ->from('occupancy')
                    ->whereColumn('occupancy.student_id', 'students.id');
            })
            ->where(function ($query) use ($q) {
                $query->where('full_name', 'like', "%{$q}%")
                      ->orWhere('student_code', 'like', "%{$q}%");
            })
            ->with(['registrations' => function ($query) {
                $query->whereHas('occupancy')
                      ->with(['occupancy.room.floor', 'occupancy.bed'])
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

                $avatarUrl = $student->avatar
                    ? $this->resolveImageUrl((string) $student->avatar)
                    : null;

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

        return match ($occupancy->status) {
            'ACTIVE', 'ROOM_CONFIRMED' => 'ACTIVE',
            'CHECKOUT_REQUESTED'       => 'CHECKOUT_REQUESTED',
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
