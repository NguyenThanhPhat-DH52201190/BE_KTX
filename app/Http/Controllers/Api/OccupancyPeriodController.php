<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ExtensionPeriodOpenedMail;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\OccupancyPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OccupancyPeriodController extends Controller
{
    public function index(): JsonResponse
    {
        $periods = OccupancyPeriod::withCount('extensions')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($periods);
    }

    public function show(int $id): JsonResponse
    {
        $period = OccupancyPeriod::withCount('extensions')->findOrFail($id);
        return response()->json($period);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:191'],
            'start_date'           => ['required', 'date'],
            'end_date'             => ['required', 'date', 'after_or_equal:start_date'],
            'extension_until_date' => ['nullable', 'date', 'after_or_equal:end_date'],
            'description'          => ['nullable', 'string'],
        ]);

        $active = OccupancyPeriod::whereIn('status', ['open', 'draft'])->first();
        if ($active) {
            return response()->json([
                'message' => "Đợt \"{$active->name}\" đang {$this->statusLabel($active->status)}. Đóng đợt đó trước khi tạo đợt mới.",
            ], 422);
        }

        $this->checkOverlap($data['start_date'], $data['end_date']);

        $period = OccupancyPeriod::create(array_merge($data, ['status' => 'draft']));

        return response()->json($period, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $period = OccupancyPeriod::findOrFail($id);

        $data = $request->validate([
            'name'                 => ['sometimes', 'string', 'max:191'],
            'start_date'           => ['sometimes', 'date'],
            'end_date'             => ['sometimes', 'date', 'after_or_equal:start_date'],
            'extension_until_date' => ['nullable', 'date'],
            'description'          => ['nullable', 'string'],
        ]);

        $startDate = $data['start_date'] ?? $period->start_date?->toDateString();
        $endDate   = $data['end_date']   ?? $period->end_date?->toDateString();

        if ($startDate && $endDate) {
            $this->checkOverlap($startDate, $endDate, $id);
        }

        $period->update($data);

        return response()->json($period);
    }

    public function open(int $id): JsonResponse
    {
        $period = OccupancyPeriod::findOrFail($id);

        if ($period->status === 'open') {
            return response()->json(['message' => 'Đợt này đã đang mở.'], 422);
        }

        if ($period->status === 'closed') {
            return response()->json(['message' => 'Đợt đã đóng không thể mở lại.'], 422);
        }

        if (! $period->extension_until_date) {
            return response()->json([
                'message' => 'Vui lòng điền ngày "Gia hạn lưu trú đến" trước khi mở đợt.',
            ], 422);
        }

        // Check no other open period exists
        $existing = OccupancyPeriod::where('status', 'open')->where('id', '!=', $id)->first();
        if ($existing) {
            return response()->json([
                'message' => "Đang có đợt gia hạn \"{$existing->name}\" đang mở. Đóng đợt đó trước khi mở đợt mới.",
            ], 422);
        }

        $period->update(['status' => 'open']);

        $this->notifyStudents($period);

        return response()->json($period);
    }

    public function close(int $id): JsonResponse
    {
        $period = OccupancyPeriod::findOrFail($id);

        if ($period->status !== 'open') {
            return response()->json(['message' => 'Chỉ có thể đóng đợt đang mở.'], 422);
        }

        $period->update(['status' => 'closed']);

        return response()->json($period);
    }

    public function destroy(int $id): JsonResponse
    {
        $period = OccupancyPeriod::withCount('extensions')->findOrFail($id);

        if ($period->status === 'open') {
            return response()->json(['message' => 'Không thể xóa đợt đang mở. Đóng đợt trước.'], 422);
        }

        if ($period->extensions_count > 0) {
            return response()->json(['message' => 'Không thể xóa đợt đã có yêu cầu gia hạn.'], 422);
        }

        $period->delete();

        return response()->json(['message' => 'Đã xóa đợt gia hạn.']);
    }

    // ──────────────── HELPERS ────────────────

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'open'  => 'đang mở',
            'draft' => 'ở trạng thái bản nháp',
            default => $status,
        };
    }

    private function checkOverlap(string $startDate, string $endDate, ?int $excludeId = null): void
    {
        $query = OccupancyPeriod::whereIn('status', ['draft', 'open'])
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $overlap = $query->first();

        if ($overlap) {
            abort(422, "Thời gian trùng với đợt gia hạn \"{$overlap->name}\".");
        }
    }

    private function notifyStudents(OccupancyPeriod $period): void
    {
        try {
            $deadline = $period->end_date?->format('d/m/Y') ?? 'xem hệ thống';
            $until    = $period->extension_until_date?->format('d/m/Y');
            $content  = "Đợt gia hạn lưu trú \"{$period->name}\" hiện đã mở. "
                      . "Hạn nộp yêu cầu: {$deadline}."
                      . ($until ? " Ngày gia hạn đến: {$until}." : '')
                      . " Nếu muốn tiếp tục ở ký túc xá, vui lòng đăng nhập và gửi yêu cầu gia hạn trước thời hạn trên.";

            $studentIds = Occupancy::where('status', 'ACTIVE')->pluck('student_id')->unique();

            \App\Models\Student::whereIn('id', $studentIds)
                ->whereNotNull('email')
                ->get()
                ->each(function ($student) use ($period, $content) {
                    try {
                        $notification = Notification::create([
                            'student_id'  => $student->id,
                            'title'       => 'Đợt gia hạn lưu trú đã mở — ' . $period->name,
                            'content'     => $content,
                            'type'        => 'extension_opened',
                            'target_type' => 'student',
                            'send_email'  => true,
                        ]);
                        DB::table('notification_recipient')->insert([
                            'notification_id' => $notification->id,
                            'student_id'      => $student->id,
                            'is_read'         => false,
                            'read_at'         => null,
                        ]);
                    } catch (\Exception) {
                        // Do not block email on notification failure
                    }

                    try {
                        // Đưa vào hàng đợi (ShouldQueue) thay vì gửi ngay — tránh chặn request
                        // "Mở đợt" trong lúc gửi email lần lượt tới toàn bộ sinh viên đang ở active.
                        Mail::to($student->email)->queue(new ExtensionPeriodOpenedMail($period, $student));
                    } catch (\Exception $e) {
                        Log::error('Gửi email thông báo thất bại', [
                            'type'       => 'extension_opened',
                            'student_id' => $student->id,
                            'email'      => $student->email,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                });
        } catch (\Exception) {
            // Must not break HTTP response
        }
    }
}
