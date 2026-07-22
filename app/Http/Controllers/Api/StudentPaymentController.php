<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElectricityBill;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StudentPaymentController extends Controller
{
    public function myBills(Request $request): JsonResponse
    {
        $account = $request->user();
        $student = Student::query()->find($account->student_id);

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
            ->with(['student', 'occupancy.room.floor'])
            ->where('student_id', $student->id)
            ->get()
            ->map(fn (RoomFeeBill $bill) => $this->formatRoomFeeBill($bill));

        $electricityItems = ElectricityBill::query()
            ->with(['student', 'occupancy.room.floor'])
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

    public function confirmFree(Request $request, int $id): JsonResponse
    {
        $account = $request->user();

        $bill = RoomFeeBill::query()->with(['student', 'occupancy.room.floor'])->find($id);

        if (! $bill) {
            return response()->json(['message' => 'Không tìm thấy hóa đơn.'], 404);
        }

        if ((int) $bill->student_id !== (int) $account->student_id) {
            return response()->json(['message' => 'Hóa đơn không thuộc sinh viên này.'], 403);
        }

        if (($bill->status ?? 'unpaid') === 'paid') {
            return response()->json(['message' => 'Hóa đơn đã được thanh toán.'], 422);
        }

        if ((float) $bill->amount > 0) {
            return response()->json(['message' => 'Hóa đơn này không được miễn phí hoàn toàn.'], 422);
        }

        DB::transaction(function () use ($bill) {
            $bill->update([
                'status'         => 'paid',
                'payment_method' => 'Miễn phí',
                'paid_at'        => Carbon::now(),
            ]);

            // Kích hoạt lưu trú nếu occupancy đang PENDING_PAYMENT
            if ($bill->occupancy_id) {
                $occupancy = Occupancy::query()->lockForUpdate()->find($bill->occupancy_id);

                if ($occupancy?->status === 'PENDING_PAYMENT' && $occupancy->bed_id) {
                    $occupancy->update(['status' => 'ACTIVE']);

                    $room     = $occupancy->room;
                    $floor    = $room?->floor;
                    $bed      = $occupancy->bed;
                    $roomCode = ($floor?->building_code ?? '') . ($room?->room_number ?? '');
                    $bedNo    = $bed?->bed_number ?? '';

                    $notification = Notification::create([
                        'student_id' => $occupancy->student_id,
                        'title'      => 'Xác nhận lưu trú thành công!',
                        'content'    => "Bạn đã được miễn phí 100% và chính thức lưu trú tại phòng {$roomCode}" . ($bedNo ? " giường #{$bedNo}" : '') . '. Chào mừng bạn đến với KTX!',
                        'type'       => 'payment_success',
                    ]);
                    DB::table('notification_recipient')->insert([
                        'notification_id' => $notification->id,
                        'student_id'      => $occupancy->student_id,
                        'is_read'         => false,
                        'read_at'         => null,
                    ]);
                }
            }
        });

        $bill->load(['student', 'occupancy.room.floor']);

        return response()->json($this->formatRoomFeeBill($bill));
    }

    private function formatRoomFeeBill(RoomFeeBill $bill): array
    {
        $occupancy = $bill->occupancy;
        $room = $occupancy?->room;

        return [
            'id' => (int) $bill->id,
            'source' => 'room_fee',
            'title' => 'Tiền phòng tháng ' . $bill->month . '/' . $bill->year,
            'period' => 'Tháng ' . $bill->month . '/' . $bill->year,
            // true nếu bill này gộp cả quý (3 tháng, xem RoomFeeBillingService::isMonthCoveredByQuarterlyBill),
            // false nếu là bill riêng 1 tháng (bill thường hoặc bill đã tách qua splitQuarterlyBillToMonthly).
            'is_quarterly' => $bill->total_days === null,
            'amount' => (float) $bill->amount,
            'original_amount' => $bill->original_amount !== null ? (float) $bill->original_amount : null,
            'discount_percent' => $bill->discount_percent !== null ? (float) $bill->discount_percent : null,
            'discount_amount' => (float) ($bill->discount_amount ?? 0),
            'discount_reason' => $bill->discount_reason,
            'due_date' => $bill->getRawOriginal('due_date'),
            'payment_method' => $bill->payment_method,
            'transaction_code' => $bill->transaction_code,
            'paid_at' => $bill->paid_at,
            'status' => $bill->status ?? 'unpaid',
            'sort_date' => $bill->getRawOriginal('due_date') ?? '',
            'room' => $room ? [
                'building_code' => $room->floor?->building_code ?? '',
                'room_number' => (string) $room->room_number,
            ] : null,
        ];
    }

    private function formatElectricityBill(ElectricityBill $bill): array
    {
        $occupancy = $bill->occupancy;
        $room = $occupancy?->room;

        return [
            'id' => (int) $bill->id,
            'source' => 'electricity',
            'title' => 'Tiền điện tháng ' . $bill->month_year,
            'period' => $bill->month_year,
            'amount' => (float) $bill->amount,
            'due_date' => $bill->getRawOriginal('due_date'),
            'payment_method' => $bill->payment_method,
            'transaction_code' => $bill->transaction_code,
            'paid_at' => $bill->paid_at,
            'status' => $bill->status ?? 'unpaid',
            'sort_date' => $bill->getRawOriginal('due_date') ?? '',
            'usage_kwh' => (int) $bill->usage_kwh,
            'unit_price' => (float) $bill->unit_price,
            'room' => $room ? [
                'building_code' => $room->floor?->building_code ?? '',
                'room_number' => (string) $room->room_number,
            ] : null,
        ];
    }
}
