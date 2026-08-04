<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use App\Models\StudentPaymentPlan;
use App\Services\ExcelService;
use App\Services\FeeDiscountService;
use App\Services\PdfService;
use App\Support\VnFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomFeeBillController extends Controller
{
    public function __construct(private readonly FeeDiscountService $discountService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = RoomFeeBill::query()
            ->with(['student', 'occupancy.registration', 'occupancy.room.floor'])
            ->orderByDesc('year')
            ->orderByDesc('month')
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
            $query->get()->map(fn (RoomFeeBill $bill) => $this->formatBill($bill))->values(),
        );
    }

    private function buildExportRows(Request $request)
    {
        $query = RoomFeeBill::query()
            ->with(['student', 'occupancy.registration', 'occupancy.room.floor'])
            ->orderByDesc('year')
            ->orderByDesc('month')
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

        return $query->get()->map(fn (RoomFeeBill $bill) => $this->formatBill($bill))->values();
    }

    public function exportPdf(Request $request, PdfService $pdfService): Response
    {
        $bills = $this->buildExportRows($request);

        return $pdfService->download(
            'pdf.room-fee-bills',
            ['bills' => $bills],
            'danh_sach_hoa_don_tien_phong_' . now()->format('dmY_His') . '.pdf',
        );
    }

    public function exportExcel(Request $request, ExcelService $excelService): Response
    {
        $bills = $this->buildExportRows($request);
        $statusLabels = ['unpaid' => 'Chưa thanh toán', 'paid' => 'Đã thanh toán', 'overdue' => 'Quá hạn', 'exempted' => 'Đã miễn'];

        $rows = $bills->values()->map(function (array $bill, int $index) use ($statusLabels) {
            return [
                $index + 1,
                $bill['student']['student_code'] ?? '',
                $bill['student']['full_name'] ?? '',
                ($bill['room']['building_code'] ?? '') . ($bill['room']['room_number'] ?? ''),
                $bill['month'] . '/' . $bill['year'],
                $bill['original_amount'] ?? $bill['amount'],
                $bill['discount_amount'] ?? 0,
                $bill['amount'],
                VnFormat::date($bill['due_date']),
                $statusLabels[$bill['status']] ?? $bill['status'],
            ];
        })->all();

        return $excelService->download(
            'danh_sach_hoa_don_tien_phong_' . now()->format('dmY_His') . '.xlsx',
            [[
                'title' => 'Hoa don tien phong',
                'headers' => ['STT', 'MSSV', 'Họ tên', 'Phòng', 'Kỳ', 'Số tiền gốc', 'Giảm giá', 'Thành tiền', 'Hạn nộp', 'Trạng thái'],
                'rows' => $rows,
            ]],
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

        // Sinh viên đang ở chế độ "đóng theo tháng" không được tạo bill gộp thủ công ở đây —
        // cron bills:generate-monthly sẽ tự bill riêng từng tháng cho họ.
        $installmentStudentIds = StudentPaymentPlan::query()
            ->where('type', 'installment')
            ->where('is_active', true)
            ->pluck('student_id');

        $occupancies = Occupancy::query()
            ->with(['student', 'registration', 'room.floor'])
            ->where('status', 'ACTIVE')
            ->whereNotNull('student_id')
            ->whereNotNull('registration_id')
            ->whereNotIn('student_id', $installmentStudentIds)
            ->get();

        $created = [];
        $skipped = 0;

        DB::transaction(function () use ($data, $occupancies, &$created, &$skipped) {
            foreach ($occupancies as $occupancy) {
                $exists = RoomFeeBill::query()
                    ->where('occupancy_id', $occupancy->id)
                    ->where('month', (int) $data['month'])
                    ->where('year', (int) $data['year'])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $baseAmount = (int) $data['amount'];
                $discount   = $this->discountService->calculate($occupancy, $baseAmount);

                $billData = [
                    'student_id'  => $occupancy->student_id,
                    'occupancy_id' => $occupancy->id,
                    'month'        => (int) $data['month'],
                    'year'         => (int) $data['year'],
                    'due_date'     => $data['due_date'],
                    'status'       => 'unpaid',
                    'amount'       => $discount['final_amount'],
                ];

                if ($discount['discount_percent'] > 0) {
                    $billData['original_amount']  = $discount['original_amount'];
                    $billData['discount_percent'] = $discount['discount_percent'];
                    $billData['discount_amount']  = $discount['discount_amount'];
                    $billData['discount_reason']  = $discount['discount_reason'];
                }

                $created[] = RoomFeeBill::query()->create($billData);
            }
        });

        return response()->json([
            'created_count' => count($created),
            'skipped_count' => $skipped,
            'items' => collect($created)
                ->map(fn (RoomFeeBill $bill) => $this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])))
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

        DB::transaction(function () use ($bill, $data) {
            $bill->update([
                'payment_method' => trim($data['payment_method']),
                'transaction_code' => trim((string) ($data['transaction_code'] ?? '')),
                'paid_at' => $data['paid_at'] ?? now(),
                'status' => 'paid',
            ]);

            $this->activatePendingOccupancy($bill);
        });

        return response()->json($this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $bill = RoomFeeBill::query()->findOrFail($id);

        $data = $request->validate([
            'status'     => ['required', 'string', Rule::in(['unpaid', 'paid', 'overdue'])],
            'admin_note' => ['nullable', 'string', 'max:191'],
        ]);

        // Hạ cấp một hóa đơn đã thanh toán về unpaid/overdue là thao tác nhạy cảm (có thể
        // vô tình khiến sinh viên bị tính lại quá hạn dù đã trả tiền) — bắt buộc phải nêu rõ
        // lý do thay vì cho đổi tự do không dấu vết như trước.
        if (($bill->status ?? 'unpaid') === 'paid'
            && $data['status'] !== 'paid'
            && empty($data['admin_note'] ?? null)) {
            return response()->json([
                'message' => 'Hóa đơn này đã thanh toán — bắt buộc nhập lý do (admin_note) khi hạ cấp về chưa thanh toán/quá hạn.',
            ], 422);
        }

        DB::transaction(function () use ($bill, $data) {
            $bill->update([
                'status'     => $data['status'],
                'paid_at'    => $data['status'] === 'paid' ? ($bill->paid_at ?? now()) : $bill->paid_at,
                'admin_note' => $data['admin_note'] ?? $bill->admin_note,
            ]);

            if ($data['status'] === 'paid') {
                $this->activatePendingOccupancy($bill);
            }
        });

        return response()->json($this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])));
    }

    public function exempt(Request $request, int $id): JsonResponse
    {
        $bill = RoomFeeBill::query()->findOrFail($id);

        $data = $request->validate([
            'admin_note'  => ['nullable', 'string', 'max:191'],
            'exempted_by' => ['nullable', 'string', 'max:191'],
        ]);

        if (($bill->status ?? 'unpaid') === 'paid') {
            return response()->json(['message' => 'Hóa đơn đã thanh toán, không thể miễn.'], 422);
        }

        DB::transaction(function () use ($bill, $data) {
            $bill->update([
                'status'      => 'exempted',
                'admin_note'  => $data['admin_note'] ?? $bill->admin_note,
                'exempted_by' => $data['exempted_by'] ?? $bill->exempted_by,
                'exempted_at' => now(),
            ]);

            $this->activatePendingOccupancy($bill);
        });

        return response()->json($this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])));
    }

    public function applyOneTimeDiscount(Request $request, int $id): JsonResponse
    {
        $bill = RoomFeeBill::query()->findOrFail($id);

        $data = $request->validate([
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'reason'           => ['nullable', 'string', 'max:191'],
        ]);

        if (in_array($bill->status ?? 'unpaid', ['paid', 'exempted'], true)) {
            return response()->json(['message' => 'Không thể giảm giá hóa đơn đã thanh toán hoặc đã miễn.'], 422);
        }

        $base            = (int) ($bill->original_amount ?? $bill->amount);
        $discountPercent = (float) $data['discount_percent'];
        $discountAmount  = (int) round($base * $discountPercent / 100);
        $finalAmount     = max(0, $base - $discountAmount);

        $bill->update([
            'original_amount'  => $base,
            'discount_percent' => $discountPercent,
            'discount_amount'  => $discountAmount,
            'discount_reason'  => $data['reason'] ?? $bill->discount_reason,
            'amount'           => $finalAmount,
        ]);

        return response()->json($this->formatBill($bill->fresh(['student', 'occupancy.registration', 'occupancy.room.floor'])));
    }

    private function activatePendingOccupancy(RoomFeeBill $bill): void
    {
        if (! $bill->occupancy_id) {
            return;
        }

        $occupancy = Occupancy::query()->lockForUpdate()->find($bill->occupancy_id);

        if ($occupancy?->status === 'PENDING_PAYMENT' && $occupancy->bed_id) {
            $occupancy->update(['status' => 'ACTIVE']);
        }
    }

    private function formatBill(RoomFeeBill $bill): array
    {
        $occupancy = $bill->occupancy;
        $room = $occupancy?->room;

        return [
            'id' => (int) $bill->id,
            'student_id' => (int) $bill->student_id,
            'occupancy_id' => (int) $bill->occupancy_id,
            // Giữ registration_id (suy từ occupancy) để frontend hiện tại không vỡ.
            'registration_id' => (int) ($occupancy?->registration_id ?? 0),
            'month' => (int) $bill->month,
            'year' => (int) $bill->year,
            // true nếu bill này gộp cả quý (3 tháng), false nếu là bill riêng 1 tháng.
            'is_quarterly' => $bill->total_days === null,
            'amount' => (float) $bill->amount,
            'original_amount' => $bill->original_amount !== null ? (float) $bill->original_amount : null,
            'discount_percent' => $bill->discount_percent !== null ? (float) $bill->discount_percent : null,
            'discount_amount' => (float) ($bill->discount_amount ?? 0),
            'discount_reason' => $bill->discount_reason,
            'admin_note' => $bill->admin_note,
            'exempted_by' => $bill->exempted_by,
            'exempted_at' => $bill->exempted_at,
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
