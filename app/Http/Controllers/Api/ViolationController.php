<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CheckoutRequest;
use App\Models\Occupancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViolationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()
            ->with(['type', 'occupancy.student', 'occupancy.registration.student', 'occupancy.room.floor', 'occupancy.bed'])
            ->orderByDesc('activity_date')
            ->orderByDesc('id');

        if ($request->filled('occupancy_id')) {
            $query->where('occupancy_id', (int) $request->query('occupancy_id'));
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
        ]);

        $occupancy = Occupancy::query()->findOrFail((int) $data['occupancy_id']);
        if ($this->isForcedCheckout($occupancy->status)) {
            return response()->json([
                'message' => 'Sinh viên đã bị buộc thôi ở, không thể thêm vi phạm mới.',
            ], 422);
        }

        $activity = Activity::query()->create([
            'occupancy_id' => (int) $data['occupancy_id'],
            'student_id' => $occupancy->student_id,
            'activity_type_id' => (int) $data['type_id'],
            'activity_date' => $data['violation_date'],
            'note' => trim((string) ($data['note'] ?? '')),
            'status' => 'pending',
            'action_taken' => null,
        ]);

        return response()->json($this->formatViolation($activity->load('type')), 201);
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
            'action_taken' => ['required', 'string', 'in:WARNING,FORCED_CHECKOUT'],
            'note' => ['nullable', 'string'],
        ]);

        $note = trim((string) ($data['note'] ?? $activity->note ?? ''));

        $activity->update([
            'note' => $note,
            'status' => 'resolved',
            'action_taken' => $data['action_taken'],
        ]);

        if ($data['action_taken'] === 'FORCED_CHECKOUT' && $activity->occupancy) {
            $occupancy = $activity->occupancy;
            $occupancy->status = 'TERMINATED';
            $occupancy->reason = $note !== '' ? $note : ($activity->type?->name ?? 'Buộc thôi ở do vi phạm nội quy.');
            $occupancy->check_out_date = now()->toDateString();
            $occupancy->save();

            // Buộc thôi ở: chốt mọi yêu cầu thôi ở đang chờ (nếu có).
            CheckoutRequest::where('occupancy_id', $occupancy->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'processed_at' => now()]);

            $bed = $occupancy->bed;
            if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
                $bed->status = 'empty';
                $bed->save();
            }
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
        return strtoupper(trim((string) $status)) === 'TERMINATED';
    }

    private function formatViolation(Activity $activity): array
    {
        $occupancy = $activity->occupancy;
        $student = $occupancy?->student ?? $occupancy?->registration?->student;
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
                'description' => $activity->type->description ?? '',
                'status' => strtolower((string) ($activity->type->status ?? 'active')),
            ] : null,
        ];
    }
}
