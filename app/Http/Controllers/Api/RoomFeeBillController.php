<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomFeeBillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RoomFeeBill::query()
            ->with(['student', 'registration.occupancy.room.floor'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id');

        if ($request->filled('student_id')) {
            $query->where('student_id', (int) $request->query('student_id'));
        }

        if ($request->filled('registration_id')) {
            $query->where('registration_id', (int) $request->query('registration_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', strtolower((string) $request->query('status')));
        }

        return response()->json(
            $query->get()->map(fn (RoomFeeBill $bill) => $this->formatBill($bill))->values(),
        );
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'amount' => ['required', 'integer', 'gt:0'],
            'due_date' => ['required', 'date'],
        ]);

        $activeStatuses = ['active', 'occupied', 'checkout_requested'];
        $occupancies = Occupancy::query()
            ->with(['student', 'registration', 'room.floor'])
            ->whereIn(DB::raw('LOWER(status)'), $activeStatuses)
            ->whereNotNull('student_id')
            ->whereNotNull('registration_id')
            ->get();

        $created = [];
        $skipped = 0;

        DB::transaction(function () use ($data, $occupancies, &$created, &$skipped) {
            foreach ($occupancies as $occupancy) {
                $exists = RoomFeeBill::query()
                    ->where('registration_id', $occupancy->registration_id)
                    ->where('month', (int) $data['month'])
                    ->where('year', (int) $data['year'])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $created[] = RoomFeeBill::query()->create([
                    'student_id' => $occupancy->student_id,
                    'registration_id' => $occupancy->registration_id,
                    'month' => (int) $data['month'],
                    'year' => (int) $data['year'],
                    'amount' => $data['amount'],
                    'due_date' => $data['due_date'],
                    'status' => 'unpaid',
                ]);
            }
        });

        return response()->json([
            'created_count' => count($created),
            'skipped_count' => $skipped,
            'items' => collect($created)
                ->map(fn (RoomFeeBill $bill) => $this->formatBill($bill->fresh(['student', 'registration.occupancy.room.floor'])))
                ->values(),
        ], 201);
    }

    public function confirmPayment(Request $request, int $id): JsonResponse
    {
        $bill = RoomFeeBill::query()->findOrFail($id);

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

        return response()->json($this->formatBill($bill->fresh(['student', 'registration.occupancy.room.floor'])));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $bill = RoomFeeBill::query()->findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['unpaid', 'paid', 'overdue'])],
        ]);

        $bill->update(['status' => $data['status']]);

        return response()->json($this->formatBill($bill->fresh(['student', 'registration.occupancy.room.floor'])));
    }

    private function formatBill(RoomFeeBill $bill): array
    {
        $occupancy = $bill->registration?->occupancy;
        $room = $occupancy?->room;

        return [
            'id' => (int) $bill->id,
            'student_id' => (int) $bill->student_id,
            'registration_id' => (int) $bill->registration_id,
            'month' => (int) $bill->month,
            'year' => (int) $bill->year,
            'amount' => (float) $bill->amount,
            'due_date' => $bill->due_date,
            'payment_method' => $bill->payment_method,
            'transaction_code' => $bill->transaction_code,
            'paid_at' => $bill->paid_at,
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
