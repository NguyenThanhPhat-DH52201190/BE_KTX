<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Occupancy;
use App\Models\Violation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ViolationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Violation::query()
            ->with(['type', 'occupancy.student', 'occupancy.registration.student', 'occupancy.room.floor', 'occupancy.bed'])
            ->orderByDesc('violation_date')
            ->orderByDesc('id');

        if ($request->filled('occupancy_id')) {
            $query->where('occupancy_id', (int) $request->query('occupancy_id'));
        }

        return response()->json(
            $query->get()->map(fn (Violation $violation) => $this->formatViolation($violation))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'occupancy_id' => ['required', 'integer', 'exists:occupancy,id'],
            'type_id' => ['required', 'integer', 'exists:violation_types,id'],
            'violation_date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $occupancy = Occupancy::query()->findOrFail((int) $data['occupancy_id']);
        if ($this->isForcedCheckout($occupancy->status)) {
            return response()->json([
                'message' => 'Sinh viên đã bị buộc thôi ở, không thể thêm vi phạm mới.',
            ], 422);
        }

        $payload = [
            'occupancy_id' => (int) $data['occupancy_id'],
            'type_id' => (int) $data['type_id'],
            'violation_date' => $data['violation_date'],
            'note' => trim((string) ($data['note'] ?? '')),
        ];

        if (Schema::hasColumn('violations', 'status')) {
            $payload['status'] = 'pending';
        }

        if (Schema::hasColumn('violations', 'action_taken')) {
            $payload['action_taken'] = null;
        }

        $violation = Violation::query()->create($payload);

        return response()->json($this->formatViolation($violation->load('type')), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $violation = Violation::query()->findOrFail($id);

        $data = $request->validate([
            'type_id' => ['required', 'integer', 'exists:violation_types,id'],
            'violation_date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $violation->update([
            'type_id' => (int) $data['type_id'],
            'violation_date' => $data['violation_date'],
            'note' => trim((string) ($data['note'] ?? '')),
        ]);

        return response()->json($this->formatViolation($violation->fresh('type')));
    }

    public function process(Request $request, int $id): JsonResponse
    {
        $violation = Violation::query()->with(['occupancy.bed'])->findOrFail($id);

        $data = $request->validate([
            'action_taken' => ['required', 'string', 'in:WARNING,FORCED_CHECKOUT'],
            'note' => ['nullable', 'string'],
        ]);

        $note = trim((string) ($data['note'] ?? $violation->note ?? ''));
        $payload = [
            'note' => $note,
        ];

        if (Schema::hasColumn('violations', 'status')) {
            $payload['status'] = 'resolved';
        }

        if (Schema::hasColumn('violations', 'action_taken')) {
            $payload['action_taken'] = $data['action_taken'];
        }

        $violation->update($payload);

        if ($data['action_taken'] === 'FORCED_CHECKOUT' && $violation->occupancy) {
            $occupancy = $violation->occupancy;
            $occupancy->status = 'forced_checkout';
            $occupancy->reason = $note !== '' ? $note : ($violation->type?->name ?? 'Buộc thôi ở do vi phạm nội quy.');
            $occupancy->check_out_date = now()->toDateString();
            $occupancy->save();

            $bed = $occupancy->bed;
            if ($bed && strtolower((string) $bed->status) !== 'maintenance') {
                $bed->status = 'empty';
                $bed->save();
            }
        }

        return response()->json($this->formatViolation(
            $violation->fresh(['type', 'occupancy.student', 'occupancy.registration.student', 'occupancy.room.floor', 'occupancy.bed']),
        ));
    }

    public function destroy(int $id): JsonResponse
    {
        Violation::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function isForcedCheckout(?string $status): bool
    {
        return strtolower(trim((string) $status)) === 'forced_checkout';
    }

    private function formatViolation(Violation $violation): array
    {
        $occupancy = $violation->occupancy;
        $student = $occupancy?->student ?? $occupancy?->registration?->student;
        $room = $occupancy?->room;
        $bed = $occupancy?->bed;
        $buildingCode = $room?->floor?->building_code ?? '';
        $roomNumber = $room?->room_number ? (string) $room->room_number : '';
        $bedNumber = $bed?->bed_number ? (string) $bed->bed_number : '';

        return [
            'id' => (int) $violation->id,
            'occupancy_id' => (int) $violation->occupancy_id,
            'type_id' => (int) $violation->type_id,
            'violation_date' => $violation->violation_date,
            'note' => $violation->note ?? '',
            'status' => $violation->status ?? 'pending',
            'action_taken' => $violation->action_taken,
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
            'type' => $violation->type ? [
                'id' => (int) $violation->type->id,
                'name' => $violation->type->name,
                'level' => strtoupper((string) $violation->type->level),
                'description' => $violation->type->description ?? '',
                'status' => strtolower((string) ($violation->type->status ?? 'active')),
            ] : null,
        ];
    }
}
