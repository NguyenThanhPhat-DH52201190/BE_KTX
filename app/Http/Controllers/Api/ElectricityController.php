<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElectricityBill;
use App\Models\ElectricityRecord;
use App\Models\Occupancy;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ElectricityController extends Controller
{
    public function records(): JsonResponse
    {
        $records = ElectricityRecord::query()
            ->with('room.floor')
            ->orderByDesc('month_year')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ElectricityRecord $record) => $this->formatRecord($record))
            ->values();

        return response()->json($records);
    }

    public function bills(Request $request): JsonResponse
    {
        $query = ElectricityBill::query()
            ->with(['student', 'occupancy.registration', 'occupancy.room.floor'])
            ->orderByDesc('month_year')
            ->orderByDesc('id');

        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->query('student_id'));
        }

        if ($request->filled('occupancy_id')) {
            $query->where('occupancy_id', (int) $request->query('occupancy_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', strtolower((string) $request->query('status')));
        }

        return response()->json(
            $query->get()->map(fn (ElectricityBill $bill) => $this->formatBill($bill))->values(),
        );
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'month_year' => ['required', 'string', 'date_format:Y-m', 'max:191'],
            'old_index' => ['required', 'integer', 'min:0'],
            'new_index' => ['required', 'integer', 'min:0'],
            'unit_price' => ['required', 'integer', 'gt:0'],
            'due_date' => ['required', 'date'],
        ]);

        if ((int) $data['new_index'] < (int) $data['old_index']) {
            return response()->json(['message' => 'Chỉ số mới phải lớn hơn hoặc bằng chỉ số cũ.'], 422);
        }

        $room = Room::query()->with('floor')->findOrFail((int) $data['room_id']);
        $latestRecord = ElectricityRecord::query()
            ->where('room_id', $room->id)
            ->orderByDesc('month_year')
            ->orderByDesc('id')
            ->first();

        if ($latestRecord && trim($data['month_year']) <= (string) $latestRecord->month_year) {
            return response()->json([
                'message' => 'Tháng ghi nhận mới phải sau tháng điện gần nhất của phòng này.',
            ], 422);
        }

        $usageKwh = (int) $data['new_index'] - (int) $data['old_index'];
        $totalAmount = $usageKwh * (float) $data['unit_price'];
        $occupancies = Occupancy::query()
            ->with(['student', 'registration'])
            ->where('room_id', $room->id)
            ->where('status', 'ACTIVE')
            ->whereNotNull('student_id')
            ->whereNotNull('registration_id')
            ->get();

        if ($occupancies->isEmpty()) {
            return response()->json(['message' => 'Phòng này chưa có sinh viên đang lưu trú để chia tiền điện.'], 422);
        }

        $record = null;
        $created = [];
        $skipped = 0;
        $amountPerStudent = round($totalAmount / $occupancies->count(), 2);

        DB::transaction(function () use ($data, $room, $usageKwh, $totalAmount, $occupancies, $amountPerStudent, &$record, &$created, &$skipped) {
            $record = ElectricityRecord::query()->create([
                'room_id' => $room->id,
                'month_year' => trim($data['month_year']),
                'old_index' => (int) $data['old_index'],
                'new_index' => (int) $data['new_index'],
                'usage_kwh' => $usageKwh,
                'unit_price' => $data['unit_price'],
                'total_amount' => $totalAmount,
            ]);

            foreach ($occupancies as $occupancy) {
                $exists = ElectricityBill::query()
                    ->where('student_id', $occupancy->student_id)
                    ->where('month_year', trim($data['month_year']))
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $created[] = ElectricityBill::query()->create([
                    'student_id' => $occupancy->student_id,
                    'occupancy_id' => $occupancy->id,
                    'month_year' => trim($data['month_year']),
                    'usage_kwh' => $usageKwh,
                    'unit_price' => $data['unit_price'],
                    'amount' => $amountPerStudent,
                    'due_date' => $data['due_date'],
                    'status' => 'unpaid',
                ]);
            }
        });

        return response()->json([
            'record' => $record ? $this->formatRecord($record->fresh('room.floor')) : null,
            'created_count' => count($created),
            'skipped_count' => $skipped,
            'items' => collect($created)
                ->map(fn (ElectricityBill $bill) => $this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])))
                ->values(),
        ], 201);
    }

    public function confirmPayment(Request $request, int $id): JsonResponse
    {
        $bill = ElectricityBill::query()->findOrFail($id);

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:191'],
            'transaction_code' => ['nullable', 'string', 'max:191'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $bill->update([
            'payment_method' => trim($data['payment_method']),
            'transaction_code' => trim((string) ($data['transaction_code'] ?? '')),
            'paid_at' => $data['paid_at'] ?? now(),
            'status' => 'paid',
        ]);

        return response()->json($this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $bill = ElectricityBill::query()->findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['unpaid', 'paid', 'overdue'])],
        ]);

        $bill->update(['status' => $data['status']]);

        return response()->json($this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])));
    }

    private function formatRecord(ElectricityRecord $record): array
    {
        $room = $record->room;

        return [
            'id' => (int) $record->id,
            'room_id' => (int) $record->room_id,
            'month_year' => $record->month_year,
            'old_index' => (int) $record->old_index,
            'new_index' => (int) $record->new_index,
            'usage_kwh' => (int) $record->usage_kwh,
            'unit_price' => (float) $record->unit_price,
            'total_amount' => (float) $record->total_amount,
            'created_at' => $record->created_at,
            'room' => $room ? [
                'id' => (int) $room->id,
                'building_code' => $room->floor?->building_code ?? '',
                'floor_number' => (int) ($room->floor?->floor_number ?? 0),
                'room_number' => (string) $room->room_number,
            ] : null,
        ];
    }

    private function formatBill(ElectricityBill $bill): array
    {
        $occupancy = $bill->occupancy;
        $room = $occupancy?->room;

        return [
            'id' => (int) $bill->id,
            'student_id' => (int) $bill->student_id,
            'occupancy_id' => (int) $bill->occupancy_id,
            // Giữ registration_id (suy từ occupancy) để frontend hiện tại không vỡ.
            'registration_id' => (int) ($occupancy?->registration_id ?? 0),
            'month_year' => $bill->month_year,
            'usage_kwh' => (int) $bill->usage_kwh,
            'unit_price' => (float) $bill->unit_price,
            'amount' => (float) $bill->amount,
            'due_date' => $bill->due_date,
            'payment_method' => $bill->payment_method,
            'transaction_code' => $bill->transaction_code,
            'paid_at' => $bill->paid_at,
            'created_at' => $bill->created_at,
            'status' => $bill->status ?? 'unpaid',
            'student' => $bill->student ? [
                'id' => (int) $bill->student->id,
                'student_code' => $bill->student->student_code ?? '',
                'full_name' => $bill->student->full_name ?? '',
                'email' => $bill->student->email ?? '',
            ] : null,
            'room' => $room ? [
                'id' => (int) $room->id,
                'building_code' => $room->floor?->building_code ?? '',
                'floor_number' => (int) ($room->floor?->floor_number ?? 0),
                'room_number' => (string) $room->room_number,
            ] : null,
        ];
    }
}
