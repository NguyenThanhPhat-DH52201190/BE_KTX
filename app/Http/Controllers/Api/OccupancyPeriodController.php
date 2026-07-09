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

        $periods->each(fn (OccupancyPeriod $period) => $this->appendSuggestedOpenDate($period));

        return response()->json($periods);
    }

    public function show(int $id): JsonResponse
    {
        $period = OccupancyPeriod::withCount('extensions')->findOrFail($id);
        $this->appendSuggestedOpenDate($period);

        return response()->json($period);
    }

    /**
     * Gợi ý (KHÔNG chặn cứng) mốc nên mở đợt gia hạn: dựa trên occupancy ACTIVE sắp hết hạn
     * sớm nhất hiện có trong hệ thống (chưa gắn với đợt nào) — dùng cho form "Tạo đợt mới"
     * trước khi có bản ghi occupancy_periods nào để tham chiếu.
     */
    public function suggestion(): JsonResponse
    {
        $targetDate = Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_out_date')
            ->whereDate('check_out_date', '>=', now()->toDateString())
            ->orderBy('check_out_date')
            ->value('check_out_date');

        if (! $targetDate) {
            return response()->json([
                'target_check_out_date' => null,
                'suggested_open_date'   => null,
                'students_count'        => 0,
            ]);
        }

        $studentsCount = Occupancy::where('status', 'ACTIVE')
            ->where('check_out_date', $targetDate)
            ->count();

        return response()->json([
            'target_check_out_date' => $targetDate,
            'suggested_open_date'   => $this->computeSuggestedOpenDate($targetDate),
            'students_count'        => $studentsCount,
        ]);
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
        $this->appendSuggestedOpenDate($period);

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
        $this->appendSuggestedOpenDate($period);

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
        $this->appendSuggestedOpenDate($period);

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
        $this->appendSuggestedOpenDate($period);

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

    /**
     * Gợi ý mốc nên mở đợt = đầu tháng liền trước tháng chứa ngày kết thúc lưu trú mục tiêu.
     * VD: kỳ lưu trú kết thúc 31/08/2027 → gợi ý mở đợt sớm nhất 01/07/2027.
     */
    private function computeSuggestedOpenDate(string $targetCheckOutDate): string
    {
        return \Carbon\Carbon::parse($targetCheckOutDate)->startOfMonth()->subMonth()->toDateString();
    }

    private function appendSuggestedOpenDate(OccupancyPeriod $period): void
    {
        $period->suggested_open_date = $period->end_date
            ? $this->computeSuggestedOpenDate($period->end_date->toDateString())
            : null;
    }

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
