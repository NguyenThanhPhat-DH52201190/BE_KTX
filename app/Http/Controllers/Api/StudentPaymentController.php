<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElectricityBill;
use App\Models\RoomFeeBill;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPaymentController extends Controller
{
    public function myBills(Request $request): JsonResponse
    {
        $email = trim((string) $request->query('email', ''));

        if ($email === '') {
            return response()->json(['message' => 'Thiếu email sinh viên.'], 422);
        }

        $student = Student::query()->where('email', $email)->first();

        if (!$student) {
            return response()->json([
                'student' => null,
                'items' => [],
                'summary' => [
                    'total_amount' => 0,
                    'unpaid_amount' => 0,
                    'paid_amount' => 0,
                    'overdue_amount' => 0,
                ],
            ]);
        }

        $roomFeeItems = RoomFeeBill::query()
            ->with(['student', 'registration.occupancy.room.floor'])
            ->where('student_id', $student->id)
            ->get()
            ->map(fn (RoomFeeBill $bill) => $this->formatRoomFeeBill($bill));

        $electricityItems = ElectricityBill::query()
            ->with(['student', 'registration.occupancy.room.floor'])
            ->where('student_id', $student->id)
            ->get()
            ->map(fn (ElectricityBill $bill) => $this->formatElectricityBill($bill));

        $items = $roomFeeItems
            ->merge($electricityItems)
            ->sortByDesc(fn (array $item) => $item['sort_date'] . '-' . str_pad((string) $item['id'], 8, '0', STR_PAD_LEFT))
            ->values();

        return response()->json([
            'student' => [
                'id' => (int) $student->id,
                'student_code' => $student->student_code ?? '',
                'full_name' => $student->full_name ?? '',
                'email' => $student->email ?? '',
            ],
            'items' => $items,
            'summary' => [
                'total_amount' => (float) $items->sum('amount'),
                'unpaid_amount' => (float) $items->where('status', 'unpaid')->sum('amount'),
                'paid_amount' => (float) $items->where('status', 'paid')->sum('amount'),
                'overdue_amount' => (float) $items->where('status', 'overdue')->sum('amount'),
            ],
        ]);
    }

    private function formatRoomFeeBill(RoomFeeBill $bill): array
    {
        $occupancy = $bill->registration?->occupancy;
        $room = $occupancy?->room;

        return [
            'id' => (int) $bill->id,
            'source' => 'room_fee',
            'title' => 'Tiền phòng tháng ' . $bill->month . '/' . $bill->year,
            'period' => 'Tháng ' . $bill->month . '/' . $bill->year,
            'amount' => (float) $bill->amount,
            'due_date' => $bill->due_date,
            'payment_method' => $bill->payment_method,
            'transaction_code' => $bill->transaction_code,
            'paid_at' => $bill->paid_at,
            'status' => $bill->status ?? 'unpaid',
            'sort_date' => (string) $bill->due_date,
            'room' => $room ? [
                'building_code' => $room->floor?->building_code ?? '',
                'room_number' => (string) $room->room_number,
            ] : null,
        ];
    }

    private function formatElectricityBill(ElectricityBill $bill): array
    {
        $occupancy = $bill->registration?->occupancy;
        $room = $occupancy?->room;

        return [
            'id' => (int) $bill->id,
            'source' => 'electricity',
            'title' => 'Tiền điện tháng ' . $bill->month_year,
            'period' => $bill->month_year,
            'amount' => (float) $bill->amount,
            'due_date' => $bill->due_date,
            'payment_method' => $bill->payment_method,
            'transaction_code' => $bill->transaction_code,
            'paid_at' => $bill->paid_at,
            'status' => $bill->status ?? 'unpaid',
            'sort_date' => (string) $bill->due_date,
            'usage_kwh' => (int) $bill->usage_kwh,
            'unit_price' => (float) $bill->unit_price,
            'room' => $room ? [
                'building_code' => $room->floor?->building_code ?? '',
                'room_number' => (string) $room->room_number,
            ] : null,
        ];
    }
}
