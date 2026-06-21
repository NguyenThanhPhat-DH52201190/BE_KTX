<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessOccupancyExtensionRequest;
use App\Http\Requests\StoreOccupancyExtensionRequest;
use App\Http\Resources\OccupancyExtensionResource;
use App\Models\Activity;
use App\Models\ElectricityBill;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\OccupancyExtension;
use App\Models\OccupancyPeriod;
use App\Models\RoomFeeBill;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OccupancyExtensionController extends Controller
{
    // ──────────────── STUDENT ────────────────

    public function studentIndex(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $items = OccupancyExtension::query()
            ->with(['occupancy.room', 'occupancy.bed', 'occupancyPeriod'])
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(OccupancyExtensionResource::collection($items)->resolve());
    }

    public function eligibility(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $occupancy = Occupancy::where('student_id', $student->id)
            ->where('status', 'ACTIVE')
            ->latest('id')
            ->first();

        $period = OccupancyPeriod::where('status', 'open')->first();

        // Điều kiện 1: đang học
        $isStudying = strtoupper($student->academic_status ?? '') === 'STUDYING';

        // Điều kiện 2: không nợ hóa đơn
        $hasRoomDebt = RoomFeeBill::where('student_id', $student->id)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->exists();
        $hasElecDebt = ElectricityBill::where('student_id', $student->id)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->exists();
        $noDebt = ! $hasRoomDebt && ! $hasElecDebt;

        // Điều kiện 3: vi phạm trong toàn bộ kỳ lưu trú (kể cả các kỳ cũ liên tiếp qua previous_occupancy_id)
        // Ngưỡng chuyển Branch B: nghiêm trọng ≥ 1, trung bình ≥ 2, nhẹ ≥ 3
        $seriousCount = 0;
        $mediumCount  = 0;
        $minorCount   = 0;
        if ($occupancy) {
            [$seriousCount, $mediumCount, $minorCount] = $this->countViolationsInChain($occupancy);
        }
        $seriousViolationOk = $seriousCount === 0;   // ≥ 1 → Branch B
        $mediumViolationOk  = $mediumCount < 2;      // ≥ 2 → Branch B
        $minorViolationOk   = $minorCount  < 3;      // ≥ 3 → Branch B

        // Điều kiện 4: chưa lên năm cuối (năm tiếp theo ≤ 4)
        $maxYear     = 4;
        $currentYear = (int) ($student->current_year ?? 0);
        $notFinalYear = ($currentYear + 1) <= $maxYear;

        // Kiểm tra đã nộp đơn cho đợt này chưa
        $alreadySubmitted = false;
        if ($occupancy && $period) {
            $alreadySubmitted = OccupancyExtension::where('occupancy_id', $occupancy->id)
                ->where('occupancy_period_id', $period->id)
                ->exists();
        }

        // Vi phạm nghiêm trọng không còn là hard block — chuyển Branch B để admin quyết định
        $hardBlocked      = ! $isStudying || ! $noDebt || ! $notFinalYear;
        $violationOk      = $seriousViolationOk && $mediumViolationOk && $minorViolationOk;
        $needsAdminReview = ! $hardBlocked && ! $violationOk;  // Nhánh B
        $autoEligible     = ! $hardBlocked && $violationOk;    // Nhánh A

        $branch = null;
        if ($autoEligible) {
            $branch = 'A';
        } elseif ($needsAdminReview) {
            $branch = 'B';
        }

        return response()->json([
            'eligible'               => $autoEligible || $needsAdminReview,
            'branch'                 => $branch,
            'has_active_occupancy'   => $occupancy !== null,
            'has_open_period'        => $period !== null,
            'already_submitted'      => $alreadySubmitted,
            'conditions'             => [
                'studying'              => $isStudying,
                'no_debt'               => $noDebt,
                'no_serious_violation'  => $seriousViolationOk,
                'medium_violations_ok'  => $mediumViolationOk,
                'minor_violations_ok'   => $minorViolationOk,
                'not_final_year'        => $notFinalYear,
            ],
            'serious_violation_count' => $seriousCount,
            'medium_violation_count'  => $mediumCount,
            'minor_violation_count'   => $minorCount,
        ]);
    }

    public function store(StoreOccupancyExtensionRequest $request): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        // Điều kiện 1: đang học
        if (strtoupper($student->academic_status ?? '') !== 'STUDYING') {
            return response()->json(['message' => 'Không đủ điều kiện: sinh viên không trong trạng thái đang học.'], 422);
        }

        // Điều kiện 4: chưa năm cuối
        $maxYear     = 4;
        $currentYear = (int) ($student->current_year ?? 0);
        if (($currentYear + 1) > $maxYear) {
            return response()->json(['message' => 'Không đủ điều kiện: sinh viên năm cuối không được gia hạn lưu trú.'], 422);
        }

        // Điều kiện 2: không nợ hóa đơn
        $hasDebt = RoomFeeBill::where('student_id', $student->id)
                ->whereIn('status', ['unpaid', 'overdue'])
                ->exists()
            || ElectricityBill::where('student_id', $student->id)
                ->whereIn('status', ['unpaid', 'overdue'])
                ->exists();

        if ($hasDebt) {
            return response()->json(['message' => 'Không đủ điều kiện: còn nợ hóa đơn tiền phòng hoặc tiền điện chưa thanh toán.'], 422);
        }

        $occupancy = Occupancy::where('student_id', $student->id)
            ->where('status', 'ACTIVE')
            ->latest('id')
            ->first();

        if (!$occupancy) {
            return response()->json(['message' => 'Bạn không có lưu trú đang hoạt động.'], 422);
        }

        $period = OccupancyPeriod::where('status', 'open')->first();

        if (!$period) {
            return response()->json(['message' => 'Hiện không có đợt gia hạn nào đang mở.'], 422);
        }

        $exists = OccupancyExtension::where('occupancy_id', $occupancy->id)
            ->where('occupancy_period_id', $period->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Bạn đã gửi yêu cầu gia hạn cho đợt này rồi.'], 422);
        }

        // Điều kiện 3: vi phạm (nghiêm trọng ≥1 / trung bình ≥2 / nhẹ ≥3 → Nhánh B)
        [$seriousCount, $mediumCount, $minorCount] = $this->countViolationsInChain($occupancy);

        // Nhánh A: tự động duyệt — không vượt ngưỡng nào
        // Nhánh B: chờ admin xét duyệt — vượt ít nhất 1 ngưỡng
        $isAutoApprove = $seriousCount === 0 && $mediumCount < 2 && $minorCount < 3;

        $extension = OccupancyExtension::create([
            'occupancy_id'        => $occupancy->id,
            'student_id'          => $student->id,
            'occupancy_period_id' => $period->id,
            'reason'              => trim($request->input('reason')),
            'status'              => $isAutoApprove ? 'approved' : 'pending',
            'requested_at'        => now(),
            'approved_at'         => $isAutoApprove ? now() : null,
        ]);

        // Nhánh A: tạo occupancy mới kỳ gia hạn, giữ nguyên phòng/giường, kết thúc kỳ cũ
        if ($isAutoApprove && $period->extension_until_date) {
            $newOccupancy = DB::transaction(function () use ($occupancy, $period) {
                // Phải COMPLETED trước để tránh vi phạm unique(student_id)
                $occupancy->update(['status' => 'COMPLETED']);

                return Occupancy::create([
                    'student_id'            => $occupancy->student_id,
                    'room_id'               => $occupancy->room_id,
                    'bed_id'                => $occupancy->bed_id,
                    'check_in_date'         => $occupancy->check_out_date,
                    'check_out_date'        => $period->extension_until_date->toDateString(),
                    'status'                => 'ACTIVE',
                    'bed_approval_status'   => 'approved',
                    'reason'                => 'Gia hạn lưu trú',
                    'previous_occupancy_id' => $occupancy->id,
                ]);
            });

            $newOccupancy->load(['room', 'bed']);
            $this->sendApprovalNotification($student, $newOccupancy);
        }

        return response()->json(
            (new OccupancyExtensionResource(
                $extension->fresh(['occupancy.room.floor.building', 'occupancy.bed', 'occupancyPeriod'])
            ))->resolve(),
            $isAutoApprove ? 200 : 201,
        );
    }

    // ──────────────── ADMIN ────────────────

    public function adminIndex(Request $request): JsonResponse
    {
        $query = OccupancyExtension::query()
            ->with(['occupancy.room.floor.building', 'occupancy.bed', 'student', 'occupancyPeriod'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('occupancy_period_id')) {
            $query->where('occupancy_period_id', (int) $request->query('occupancy_period_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('student_code', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(OccupancyExtensionResource::collection($query->get())->resolve());
    }

    public function adminShow(int $id): JsonResponse
    {
        $extension = OccupancyExtension::with(['occupancy.room.floor.building', 'occupancy.bed', 'student', 'occupancyPeriod'])
            ->findOrFail($id);

        return response()->json((new OccupancyExtensionResource($extension))->resolve());
    }

    public function approve(ProcessOccupancyExtensionRequest $request, int $id): JsonResponse
    {
        $extension = OccupancyExtension::with(['occupancyPeriod', 'occupancy.student', 'occupancy.room', 'occupancy.bed'])
            ->findOrFail($id);

        if ($extension->status !== 'pending') {
            return response()->json(['message' => 'Chỉ có thể duyệt yêu cầu đang chờ xét duyệt.'], 422);
        }

        $newOccupancy = null;
        $student      = null;

        DB::transaction(function () use ($extension, $request, &$newOccupancy, &$student) {
            $extension->update([
                'status'      => 'approved',
                'admin_note'  => $request->input('admin_note'),
                'approved_at' => now(),
            ]);

            $occupancy = $extension->occupancy;
            $period    = $extension->occupancyPeriod;
            $student   = $occupancy?->student;

            if ($occupancy && $period?->extension_until_date) {
                // Phải COMPLETED trước để tránh vi phạm unique(student_id)
                if ($occupancy->status === 'ACTIVE') {
                    $occupancy->update(['status' => 'COMPLETED']);
                }

                $newOccupancy = Occupancy::create([
                    'student_id'            => $occupancy->student_id,
                    'room_id'               => $occupancy->room_id,
                    'bed_id'                => $occupancy->bed_id,
                    'check_in_date'         => $occupancy->check_out_date,
                    'check_out_date'        => $period->extension_until_date->toDateString(),
                    'status'                => 'ACTIVE',
                    'bed_approval_status'   => 'approved',
                    'reason'                => 'Gia hạn lưu trú',
                    'previous_occupancy_id' => $occupancy->id,
                ]);
            }
        });

        if ($newOccupancy && $student) {
            $newOccupancy->load(['room', 'bed']);
            $this->sendApprovalNotification($student, $newOccupancy);
        }

        return response()->json(
            (new OccupancyExtensionResource(
                $extension->fresh(['occupancy.room.floor.building', 'occupancy.bed', 'student', 'occupancyPeriod'])
            ))->resolve(),
        );
    }

    public function reject(ProcessOccupancyExtensionRequest $request, int $id): JsonResponse
    {
        $extension = OccupancyExtension::with('occupancy.student')->findOrFail($id);

        if ($extension->status !== 'pending') {
            return response()->json(['message' => 'Chỉ có thể từ chối yêu cầu đang chờ xét duyệt.'], 422);
        }

        $adminNote = $request->input('admin_note', '');

        $extension->update([
            'status'     => 'rejected',
            'admin_note' => $adminNote,
        ]);

        $student = $extension->occupancy?->student;
        if ($student) {
            $this->sendRejectionNotification($student, (string) $adminNote);
        }

        return response()->json(
            (new OccupancyExtensionResource(
                $extension->fresh(['occupancy.room.floor.building', 'occupancy.bed', 'student', 'occupancyPeriod'])
            ))->resolve(),
        );
    }

    public function stats(): JsonResponse
    {
        $daysInMonth = now()->daysInMonth;
        $windowEnd   = now()->addDays($daysInMonth)->toDateString();

        $activePeriod = OccupancyPeriod::where('status', 'open')->first();

        $expiringSoonIds = Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_out_date')
            ->whereDate('check_out_date', '<=', $windowEnd)
            ->pluck('id');

        $expiringSoonCount = $expiringSoonIds->count();

        $submittedQuery = OccupancyExtension::whereIn('occupancy_id', $expiringSoonIds);
        if ($activePeriod) {
            $submittedQuery->where('occupancy_period_id', $activePeriod->id);
        }
        $submittedCount = $submittedQuery->count();

        $approvedCount = OccupancyExtension::where('status', 'approved')->count();
        $rejectedCount = OccupancyExtension::where('status', 'rejected')->count();

        return response()->json([
            'active_extension_period' => $activePeriod ? [
                'id'                   => $activePeriod->id,
                'name'                 => $activePeriod->name,
                'end_date'             => $activePeriod->end_date?->toDateString(),
                'extension_until_date' => $activePeriod->extension_until_date?->toDateString(),
            ] : null,
            'expiring_soon'           => $expiringSoonCount,
            'extension_submitted'     => $submittedCount,
            'extension_not_submitted' => max(0, $expiringSoonCount - $submittedCount),
            'extension_approved'      => $approvedCount,
            'extension_rejected'      => $rejectedCount,
            'days_window'             => $daysInMonth,
        ]);
    }

    // ──────────────── HELPERS ────────────────

    /**
     * Đếm vi phạm SERIOUS / MEDIUM / MINOR cho toàn bộ kỳ lưu trú liên tiếp.
     * Traversal theo previous_occupancy_id để không bỏ sót violations từ các kỳ cũ.
     *
     * @return array{0: int, 1: int, 2: int} [seriousCount, mediumCount, minorCount]
     */
    private function countViolationsInChain(Occupancy $occupancy): array
    {
        $ids     = [];
        $current = $occupancy;

        while ($current !== null) {
            $ids[] = $current->id;
            $current = $current->previous_occupancy_id
                ? Occupancy::find($current->previous_occupancy_id)
                : null;
        }

        $violations = Activity::whereIn('occupancy_id', $ids)
            ->whereHas('type', fn ($q) => $q->where('category', 'negative'))
            ->with('type')
            ->get();

        $serious = 0;
        $medium  = 0;
        $minor   = 0;
        foreach ($violations as $v) {
            $level = strtoupper($v->type->level ?? '');
            if ($level === 'SERIOUS') { $serious++; }
            if ($level === 'MEDIUM')  { $medium++;  }
            if ($level === 'MINOR')   { $minor++;   }
        }

        return [$serious, $medium, $minor];
    }

    private function sendApprovalNotification(Student $student, Occupancy $newOccupancy): void
    {
        $roomNumber = $newOccupancy->room?->room_number ?? '?';
        $bedNumber  = $newOccupancy->bed?->bed_number   ?? '?';
        $checkIn    = $newOccupancy->check_in_date  ?? '';
        $checkOut   = $newOccupancy->check_out_date ?? '';

        $content = "Gia hạn lưu trú thành công! Bạn tiếp tục ở phòng {$roomNumber}, giường {$bedNumber}, "
            . "từ ngày {$checkIn} đến ngày {$checkOut}.";

        try {
            $notification = Notification::create([
                'student_id'  => $student->id,
                'title'       => 'Gia hạn lưu trú thành công',
                'content'     => $content,
                'type'        => 'extension_approved',
                'target_type' => 'individual',
                'send_email'  => true,
            ]);

            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $student->id,
                'is_read'         => false,
                'read_at'         => null,
            ]);

            if ($student->email) {
                $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px">
  <h2 style="color:#16a34a">Gia hạn lưu trú thành công — KTX</h2>
  <p>Kính gửi <strong>{$student->full_name}</strong> ({$student->student_code}),</p>
  <p>Yêu cầu gia hạn lưu trú của bạn đã được <strong>chấp thuận</strong>.</p>
  <table style="border-collapse:collapse;width:100%;margin:16px 0">
    <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:bold;width:120px">Phòng</td><td style="padding:8px;border:1px solid #e5e7eb">{$roomNumber}</td></tr>
    <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:bold">Giường</td><td style="padding:8px;border:1px solid #e5e7eb">{$bedNumber}</td></tr>
    <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:bold">Từ ngày</td><td style="padding:8px;border:1px solid #e5e7eb">{$checkIn}</td></tr>
    <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:bold">Đến ngày</td><td style="padding:8px;border:1px solid #e5e7eb">{$checkOut}</td></tr>
  </table>
  <p style="color:#6b7280;font-size:12px">Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
</div>
HTML;
                Mail::send([], [], function ($message) use ($student, $body) {
                    $message->to($student->email, $student->full_name)
                        ->subject('KTX — Gia hạn lưu trú thành công')
                        ->html($body);
                });
            }
        } catch (\Throwable $e) {
            Log::error("[ExtensionApproval] Gửi thông báo thất bại student #{$student->id}: " . $e->getMessage());
        }
    }

    private function sendRejectionNotification(Student $student, string $adminNote): void
    {
        $noteHtml = $adminNote
            ? "<p>Lý do từ admin: <em>{$adminNote}</em></p>"
            : '';

        $content = 'Yêu cầu gia hạn lưu trú của bạn đã bị từ chối.'
            . ($adminNote ? " Lý do: {$adminNote}." : '')
            . ' Lưu trú sẽ kết thúc vào ngày hết hạn. Nếu muốn ở lại, vui lòng đăng ký mới trong đợt tiếp theo.';

        try {
            $notification = Notification::create([
                'student_id'  => $student->id,
                'title'       => 'Yêu cầu gia hạn bị từ chối',
                'content'     => $content,
                'type'        => 'extension_rejected',
                'target_type' => 'individual',
                'send_email'  => true,
            ]);

            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $student->id,
                'is_read'         => false,
                'read_at'         => null,
            ]);

            if ($student->email) {
                $body = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px">
  <h2 style="color:#dc2626">Yêu cầu gia hạn lưu trú bị từ chối — KTX</h2>
  <p>Kính gửi <strong>{$student->full_name}</strong> ({$student->student_code}),</p>
  <p>Yêu cầu gia hạn lưu trú của bạn đã bị <strong>từ chối</strong>.</p>
  {$noteHtml}
  <p>Lưu trú của bạn sẽ kết thúc vào ngày hết hạn hiện tại. Nếu muốn tiếp tục ở lại KTX, vui lòng nộp đơn đăng ký mới trong đợt đăng ký tiếp theo.</p>
  <p style="color:#6b7280;font-size:12px">Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
</div>
HTML;
                Mail::send([], [], function ($message) use ($student, $body) {
                    $message->to($student->email, $student->full_name)
                        ->subject('KTX — Yêu cầu gia hạn lưu trú bị từ chối')
                        ->html($body);
                });
            }
        } catch (\Throwable $e) {
            Log::error("[ExtensionRejection] Gửi thông báo thất bại student #{$student->id}: " . $e->getMessage());
        }
    }

    private function resolveStudent(Request $request): ?Student
    {
        if ($request->filled('student_id')) {
            return Student::query()->find((int) $request->input('student_id'));
        }

        $email = trim((string) ($request->input('email') ?: $request->query('email', '')));

        if ($email === '') {
            return null;
        }

        return Student::query()->where('email', $email)->first();
    }
}
