<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Blacklist;
use App\Models\CheckoutRequest;
use App\Models\Occupancy;
use App\Services\StudentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ViolationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()
            ->with(['type', 'student', 'occupancy.student', 'occupancy.registration.student', 'occupancy.room.floor', 'occupancy.bed'])
            ->orderByDesc('activity_date')
            ->orderByDesc('id');

        $account = $request->user();

        if ($account && $account->role === 'student') {
            // Route dùng chung role:admin,student (sinh viên xem hoạt động/vi phạm của
            // chính mình ở "Hoạt động của tôi" / "Phòng của tôi"). Không tin tưởng
            // occupancy_id/student_id/student_email do client gửi — luôn ép về đúng
            // student_id của tài khoản đang đăng nhập, bỏ qua mọi filter client truyền vào.
            $query->where('student_id', $account->student_id);
        } else {
            if ($request->filled('occupancy_id')) {
                $query->where('occupancy_id', (int) $request->query('occupancy_id'));
            }

            if ($request->filled('student_id')) {
                $query->where('student_id', (int) $request->query('student_id'));
            }

            if ($request->filled('student_email')) {
                $email = (string) $request->query('student_email');
                $query->where(function ($studentScope) use ($email) {
                    $studentScope
                        ->whereHas('student', fn ($studentQuery) => $studentQuery->where('email', $email))
                        ->orWhereHas('occupancy.student', fn ($studentQuery) => $studentQuery->where('email', $email));
                });
            }
        }

        return response()->json(
            $query->get()->map(fn (Activity $activity) => $this->formatViolation($activity))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'occupancy_id' => ['required', 'integer', 'exists:occupancy,id'],
            'type_id' => ['required', 'integer', 'exists:activity_types,id'],
            'violation_date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
            'action_taken' => ['nullable', 'string', Rule::in([
                'reward_recorded',
                'reminded',
                'warned',
                'force_evicted',
                'WARNING',
                'FORCED_CHECKOUT',
            ])],
        ]);

        $occupancy = Occupancy::query()->findOrFail((int) $data['occupancy_id']);
        if ($this->isForcedCheckout($occupancy->status)) {
            return response()->json([
                'message' => 'Sinh viên không còn lưu trú tại KTX, không thể ghi nhận hoạt động mới.',
            ], 422);
        }

        $type = ActivityType::query()->findOrFail((int) $data['type_id']);
        $actionTaken = $this->resolveActionTaken($type, $data['action_taken'] ?? null);
        $status = $actionTaken ? 'resolved' : 'pending';

        $activity = Activity::query()->create([
            'occupancy_id' => (int) $data['occupancy_id'],
            'student_id' => $occupancy->student_id,
            'activity_type_id' => (int) $data['type_id'],
            'activity_date' => $data['violation_date'],
            'note' => trim((string) ($data['note'] ?? '')),
            'status' => $status,
            'action_taken' => $actionTaken,
        ]);

        if ($actionTaken === 'force_evicted') {
            $this->applyForcedEviction($activity->load(['type', 'occupancy.bed', 'occupancy.student']), trim((string) ($data['note'] ?? '')));
        } else {
            $this->notifyActivity($activity->fresh(['type', 'student', 'occupancy.student']));
        }

        return response()->json($this->formatViolation(
            $activity->fresh(['type', 'student', 'occupancy.student', 'occupancy.registration.student', 'occupancy.room.floor', 'occupancy.bed']),
        ), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activity = Activity::query()->findOrFail($id);

        $data = $request->validate([
            'type_id' => ['required', 'integer', 'exists:activity_types,id'],
            'violation_date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $activity->update([
            'activity_type_id' => (int) $data['type_id'],
            'activity_date' => $data['violation_date'],
            'note' => trim((string) ($data['note'] ?? '')),
        ]);

        return response()->json($this->formatViolation($activity->fresh('type')));
    }

    public function process(Request $request, int $id): JsonResponse
    {
        $activity = Activity::query()->with(['occupancy.bed'])->findOrFail($id);

        $data = $request->validate([
            'action_taken' => ['required', 'string', Rule::in(['reminded', 'warned', 'force_evicted', 'WARNING', 'FORCED_CHECKOUT'])],
            'note' => ['nullable', 'string'],
        ]);

        $note = trim((string) ($data['note'] ?? $activity->note ?? ''));
        $actionTaken = $this->normalizeActionTaken((string) $data['action_taken']);

        $activity->update([
            'note' => $note,
            'status' => 'resolved',
            'action_taken' => $actionTaken,
        ]);

        if ($actionTaken === 'force_evicted') {
            $this->applyForcedEviction($activity->load(['type', 'occupancy.bed', 'occupancy.student']), $note);
        } else {
            $this->notifyActivity($activity->fresh(['type', 'student', 'occupancy.student']));
        }

        return response()->json($this->formatViolation(
            $activity->fresh(['type', 'occupancy.student', 'occupancy.registration.student', 'occupancy.room.floor', 'occupancy.bed']),
        ));
    }

    public function destroy(int $id): JsonResponse
    {
        Activity::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function isForcedCheckout(?string $status): bool
    {
        return in_array(strtoupper(trim((string) $status)), ['TERMINATED', 'COMPLETED'], true);
    }

    private function resolveActionTaken(ActivityType $type, ?string $requestedAction): ?string
    {
        if (($type->category ?? 'negative') === 'positive') {
            return 'reward_recorded';
        }

        return $requestedAction ? $this->normalizeActionTaken($requestedAction) : null;
    }

    private function normalizeActionTaken(string $action): string
    {
        return match (strtoupper(trim($action))) {
            'WARNING' => 'reminded',
            'FORCED_CHECKOUT' => 'force_evicted',
            default => trim($action),
        };
    }

    private function applyForcedEviction(Activity $activity, string $note): void
    {
        if (! $activity->occupancy) {
            return;
        }

        DB::transaction(function () use ($activity, $note) {
            $activity->loadMissing(['type', 'occupancy.bed', 'occupancy.student']);
            $occupancy = $activity->occupancy;

            $occupancy->status = 'COMPLETED';
            $occupancy->reason = 'FORCE_EVICTED';
            $occupancy->check_out_date = now()->toDateString();
            $occupancy->save();

            CheckoutRequest::where('occupancy_id', $occupancy->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'processed_at' => now()]);

            $bed = $occupancy->bed;
            if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
                $bed->status = 'active';
                $bed->save();
            }

            if ($occupancy->student_id) {
                Blacklist::query()->firstOrCreate(
                    ['student_id' => $occupancy->student_id],
                    [
                        'reason' => trim(($activity->type?->name ?? 'Vi phạm nghiêm trọng') . ($note !== '' ? ' - ' . $note : '')),
                        'source' => 'serious_violation',
                        'created_by' => null,
                    ],
                );
            }
        });

        if ($activity->occupancy?->student) {
            app(StudentNotificationService::class)->notifyStudent(
                $activity->occupancy->student,
                'Buộc thôi ở do vi phạm nghiêm trọng',
                'Bạn đã bị buộc thôi ở tại KTX do vi phạm nghiêm trọng nội quy.' . ($note !== '' ? " Lý do: {$note}" : ''),
                'force_checkout',
                $activity->id,
            );
        }
    }

    private function notifyActivity(Activity $activity): void
    {
        $student = $activity->student ?? $activity->occupancy?->student;

        if (! $student) {
            return;
        }

        $isPositive = ($activity->type?->category ?? 'negative') === 'positive';
        $typeName = $activity->type?->name ?? ($isPositive ? 'Hoạt động tích cực' : 'Vi phạm nội quy');
        $noteText = $activity->note ? " Ghi chú: {$activity->note}" : '';
        $actionText = match ($activity->action_taken) {
            'reminded' => ' Bạn đã được nhắc nhở.',
            'warned' => ' Bạn đã bị cảnh cáo.',
            default => '',
        };

        $title = $isPositive ? 'Ghi nhận hoạt động tích cực' : 'Ghi nhận vi phạm nội quy';
        $content = ($isPositive
            ? "Bạn được ghi nhận hoạt động tích cực: {$typeName}."
            : "Bạn bị ghi nhận vi phạm: {$typeName}."
        ) . $actionText . $noteText;

        app(StudentNotificationService::class)->notifyStudent(
            $student,
            $title,
            $content,
            $isPositive ? 'reward_recorded' : 'violation_recorded',
            $activity->id,
        );
    }

    private function formatViolation(Activity $activity): array
    {
        $occupancy = $activity->occupancy;
        $student = $activity->student ?? $occupancy?->student ?? $occupancy?->registration?->student;
        $room = $occupancy?->room;
        $bed = $occupancy?->bed;
        $buildingCode = $room?->floor?->building_code ?? '';
        $roomNumber = $room?->room_number ? (string) $room->room_number : '';
        $bedNumber = $bed?->bed_number ? (string) $bed->bed_number : '';

        return [
            'id' => (int) $activity->id,
            'occupancy_id' => (int) $activity->occupancy_id,
            // Giữ key API cũ (type_id/violation_date) cho frontend, map từ cột mới.
            'type_id' => (int) $activity->activity_type_id,
            'violation_date' => $activity->activity_date,
            'note' => $activity->note ?? '',
            'status' => $activity->status ?? 'pending',
            'action_taken' => $activity->action_taken,
            'student' => $student ? [
                'id' => (int) $student->id,
                'student_code' => $student->student_code ?? '',
                'full_name' => $student->full_name ?? '',
            ] : null,
            'room' => [
                'building_code' => $buildingCode,
                'room_number' => $roomNumber,
                'display_name' => trim($buildingCode . $roomNumber) ?: null,
            ],
            'bed' => [
                'bed_number' => $bedNumber,
                'display_name' => $bedNumber !== '' ? '#' . $bedNumber : null,
            ],
            'type' => $activity->type ? [
                'id' => (int) $activity->type->id,
                'name' => $activity->type->name,
                'level' => strtoupper((string) $activity->type->level),
                'category' => $activity->type->category ?? 'negative',
                'points' => (int) ($activity->type->points ?? 0),
                'description' => $activity->type->description ?? '',
                'status' => strtolower((string) ($activity->type->status ?? 'active')),
            ] : null,
        ];
    }
}
