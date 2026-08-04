<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CheckoutRequest;
use App\Models\ElectricityBill;
use App\Models\Occupancy;
use App\Models\RoomFeeBill;
use App\Models\StudentSupportRequest;
use App\Services\ExcelService;
use App\Services\PdfService;
use App\Support\VnFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class OccupancyController extends Controller
{
    public function detail(int $id): JsonResponse
    {
        $occupancy = Occupancy::query()
            ->with(['student', 'registration', 'room.floor', 'bed'])
            ->findOrFail($id);

        return response()->json($this->buildDetailPayload($occupancy));
    }

    private function buildListExportRows(Request $request)
    {
        // Chỉ lấy các trạng thái thực sự "đã/đang lưu trú tại KTX" — cùng whitelist với
        // occupancy_history trong buildDetailPayload(), loại bỏ các bản ghi tiền lưu trú
        // (ROOM_CONFIRMED, PENDING_PAYMENT, PROPOSED) hoặc đã hủy (CANCELLED) chưa từng ở thật.
        $query = Occupancy::query()
            ->with(['student', 'room.floor', 'bed'])
            ->whereIn('status', ['ACTIVE', 'COMPLETED', 'CHECKOUT_REQUESTED', 'TERMINATED'])
            ->orderByDesc('check_in_date')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }

        return $query->get()->map(function (Occupancy $occ) {
            $room = $occ->room;

            return [
                'student_code' => $occ->student?->student_code ?? '',
                'full_name' => $occ->student?->full_name ?? '',
                'building_code' => $room?->floor?->building_code ?? '',
                'room_number' => $room?->room_number ? (string) $room->room_number : '',
                'bed_number' => $occ->bed?->bed_number ? (string) $occ->bed->bed_number : '',
                'check_in_date' => $occ->check_in_date,
                'check_out_date' => $occ->check_out_date,
                'status' => $occ->status,
            ];
        })->values();
    }

    public function exportListPdf(Request $request, PdfService $pdfService): Response
    {
        $occupancies = $this->buildListExportRows($request);

        return $pdfService->download(
            'pdf.occupancies',
            ['occupancies' => $occupancies],
            'danh_sach_luu_tru_' . now()->format('dmY_His') . '.pdf',
        );
    }

    public function exportListExcel(Request $request, ExcelService $excelService): Response
    {
        $occupancies = $this->buildListExportRows($request);
        $statusLabels = [
            'ACTIVE' => 'Đang lưu trú',
            'PENDING_PAYMENT' => 'Chờ thanh toán',
            'ROOM_CONFIRMED' => 'Đã xác nhận phòng',
            'PROPOSED' => 'Đề xuất phòng',
            'CHECKOUT_REQUESTED' => 'Yêu cầu thôi ở',
            'COMPLETED' => 'Đã thôi ở',
            'TERMINATED' => 'Đã kết thúc',
        ];

        $rows = $occupancies->values()->map(function (array $item, int $index) use ($statusLabels) {
            return [
                $index + 1,
                $item['student_code'],
                $item['full_name'],
                $item['building_code'] . $item['room_number'],
                $item['bed_number'],
                VnFormat::date($item['check_in_date']),
                VnFormat::date($item['check_out_date']),
                $statusLabels[$item['status']] ?? $item['status'],
            ];
        })->all();

        return $excelService->download(
            'danh_sach_luu_tru_' . now()->format('dmY_His') . '.xlsx',
            [[
                'title' => 'Danh sach luu tru',
                'headers' => ['STT', 'MSSV', 'Họ tên', 'Phòng', 'Giường', 'Ngày nhận phòng', 'Ngày trả phòng', 'Trạng thái'],
                'rows' => $rows,
            ]],
        );
    }

    private function buildDetailExportPayload(int $id): array
    {
        $occupancy = Occupancy::query()
            ->with(['student', 'registration', 'room.floor', 'bed'])
            ->findOrFail($id);

        $payload = $this->buildDetailPayload($occupancy);

        $room = $occupancy->room;
        $payload['identity'] = [
            'student_code' => $occupancy->student?->student_code ?? '',
            'full_name' => $occupancy->student?->full_name ?? '',
            'building_code' => $room?->floor?->building_code ?? '',
            'room_number' => $room?->room_number ? (string) $room->room_number : '',
            'bed_number' => $occupancy->bed?->bed_number ? (string) $occupancy->bed->bed_number : '',
            'check_in_date' => $occupancy->check_in_date,
            'check_out_date' => $occupancy->check_out_date,
            'status' => $occupancy->status,
        ];

        return $payload;
    }

    public function exportDetailPdf(int $id, PdfService $pdfService): Response
    {
        $payload = $this->buildDetailExportPayload($id);

        return $pdfService->download(
            'pdf.occupancy-detail',
            $payload,
            'ho_so_luu_tru_' . ($payload['identity']['student_code'] ?: $id) . '.pdf',
        );
    }

    public function exportDetailExcel(int $id, ExcelService $excelService): Response
    {
        $payload = $this->buildDetailExportPayload($id);
        $identity = $payload['identity'];

        $infoRows = [
            ['MSSV', $identity['student_code']],
            ['Họ tên', $identity['full_name']],
            ['Phòng', $identity['building_code'] . $identity['room_number']],
            ['Giường', $identity['bed_number']],
            ['Ngày nhận phòng', VnFormat::date($identity['check_in_date'])],
            ['Ngày trả phòng', VnFormat::date($identity['check_out_date'])],
            ['Trạng thái', $identity['status']],
            ['Địa chỉ thường trú', $payload['student']['permanent_address'] ?? ''],
            ['Họ tên cha', $payload['family']['father_name'] ?? ''],
            ['SĐT cha', $payload['family']['father_phone'] ?? ''],
            ['Họ tên mẹ', $payload['family']['mother_name'] ?? ''],
            ['SĐT mẹ', $payload['family']['mother_phone'] ?? ''],
            ['Tổng công nợ chưa thanh toán', $payload['total_debt']],
            ['Nợ quá hạn/chưa thanh toán', $payload['unpaid_debt']],
        ];

        $historyRows = collect($payload['occupancy_history'])->values()->map(fn (array $item, int $index) => [
            $index + 1,
            $item['period_name'] ?? trim(($item['school_year'] ?? '') . ' ' . ($item['semester'] ?? '')),
            $item['building_code'] . $item['room_number'],
            $item['bed_number'],
            VnFormat::date($item['check_in_date']),
            VnFormat::date($item['check_out_date']),
            $item['status'],
        ])->all();

        $violationRows = collect($payload['recent_violations'])->values()->map(fn (array $item, int $index) => [
            $index + 1,
            VnFormat::date($item['activity_date']),
            $item['type_name'],
            $item['level'],
            $item['note'],
        ])->all();

        $roomChangeRows = collect($payload['room_change_history'])->values()->map(fn (array $item, int $index) => [
            $index + 1,
            VnFormat::date($item['transferred_at']),
            $item['old_room_code'] ?? '',
            $item['new_room_code'] ?? '',
            $item['transfer_reason'] ?? '',
        ])->all();

        return $excelService->download(
            'ho_so_luu_tru_' . ($identity['student_code'] ?: $id) . '.xlsx',
            [
                ['title' => 'Thong tin', 'headers' => ['Trường', 'Giá trị'], 'rows' => $infoRows],
                ['title' => 'Lich su luu tru', 'headers' => ['STT', 'Kỳ', 'Phòng', 'Giường', 'Ngày nhận phòng', 'Ngày trả phòng', 'Trạng thái'], 'rows' => $historyRows],
                ['title' => 'Vi pham gan day', 'headers' => ['STT', 'Ngày', 'Loại', 'Mức độ', 'Ghi chú'], 'rows' => $violationRows],
                ['title' => 'Lich su doi phong', 'headers' => ['STT', 'Ngày', 'Từ', 'Đến', 'Lý do'], 'rows' => $roomChangeRows],
            ],
        );
    }

    private function buildDetailPayload(Occupancy $occupancy): array
    {
        $student     = $occupancy->student;
        $registration = $occupancy->registration;

        $avatarRaw = $student?->avatar ?? $registration?->avatar_url;
        $avatarUrl = $avatarRaw ? $this->resolveImageUrl((string) $avatarRaw) : null;

        $studentData = [
            'avatar'            => $avatarUrl,
            'permanent_address' => $student?->permanent_address,
            'previous_address'  => null,
            'current_year'      => $student?->current_year,
        ];

        $familyData = [
            'father_name'       => $registration?->father_name,
            'father_birth_year' => $registration?->father_birth_year,
            'father_phone'      => $registration?->father_phone,
            'father_occupation' => $registration?->father_job,
            'mother_name'       => $registration?->mother_name,
            'mother_birth_year' => $registration?->mother_birth_year,
            'mother_phone'      => $registration?->mother_phone,
            'mother_occupation' => $registration?->mother_job,
            'parent_address'    => $registration?->parent_address,
        ];

        // All occupancies for this student (history)
        $historyItems = Occupancy::query()
            ->with(['registration.period', 'room.floor', 'bed'])
            ->where('student_id', $occupancy->student_id)
            ->whereIn('status', ['ACTIVE', 'COMPLETED', 'CHECKOUT_REQUESTED', 'TERMINATED'])
            ->orderByDesc('check_in_date')
            ->get()
            ->map(function (Occupancy $occ) use ($occupancy) {
                $room   = $occ->room;
                $floor  = $room?->floor;
                $period = $occ->registration?->period;

                return [
                    'id'            => $occ->id,
                    'school_year'   => $period?->school_year ?? $occ->registration?->school_year,
                    'semester'      => $period?->semester ?? $occ->registration?->semester,
                    'period_name'   => $period?->name,
                    'building_code' => $floor?->building_code ?? '',
                    'floor_number'  => $floor?->floor_number,
                    'room_number'   => $room?->room_number ? (string) $room->room_number : '',
                    'bed_number'    => $occ->bed?->bed_number ? (string) $occ->bed->bed_number : '',
                    'check_in_date'  => $occ->check_in_date,
                    'check_out_date' => $occ->check_out_date,
                    'status'         => $occ->status,
                    'is_current'     => $occ->id === $occupancy->id,
                ];
            })->values()->all();

        // 3 most recent negative-category activities for this student
        $recentViolations = Activity::query()
            ->with(['type'])
            ->where('student_id', $occupancy->student_id)
            ->whereHas('type', fn ($q) => $q->where('category', 'negative'))
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (Activity $a) => [
                'id'           => $a->id,
                'type_name'    => $a->type?->name ?? '',
                'level'        => strtoupper((string) ($a->type?->level ?? '')),
                'activity_date' => $a->activity_date,
                'note'         => $a->note ?? '',
                'action_taken' => $a->action_taken,
            ])->values()->all();

        // Current month invoice for this occupancy — dùng coveringMonth() vì 1 hóa
        // đơn có thể gộp nhiều tháng (quý), không còn khớp chính xác month/year nữa.
        $now = now();
        $currentBill = RoomFeeBill::query()
            ->where('occupancy_id', $occupancy->id)
            ->coveringMonth($now->month, $now->year)
            ->first();

        $currentInvoice = $currentBill ? [
            'id'       => $currentBill->id,
            'month'    => $currentBill->month,
            'year'     => $currentBill->year,
            'amount'   => $currentBill->amount,
            'status'   => $currentBill->status,
            'due_date' => $currentBill->due_date?->toDateString(),
        ] : null;

        // Total unpaid debt for this occupancy
        $totalDebt = (int) RoomFeeBill::query()
            ->where('occupancy_id', $occupancy->id)
            ->where('status', '!=', 'paid')
            ->sum('amount');

        // Nợ UNPAID/OVERDUE (tiền phòng + tiền điện) — chỉ để hiển thị thông tin cho admin
        // trước khi duyệt thôi ở, KHÔNG dùng để chặn duyệt.
        $unpaidDebt = (int) RoomFeeBill::query()
                ->where('occupancy_id', $occupancy->id)
                ->whereIn('status', ['unpaid', 'overdue'])
                ->sum('amount')
            + (int) ElectricityBill::query()
                ->where('occupancy_id', $occupancy->id)
                ->whereIn('status', ['unpaid', 'overdue'])
                ->sum('amount');

        $checkoutRequest = $occupancy->pendingCheckoutRequest;

        // Nếu KHÔNG có yêu cầu pending, kiểm tra xem có yêu cầu nào vừa bị sinh viên tự hủy không —
        // để admin (vào từ thông báo cũ) hiểu rõ lý do không còn thấy khung yêu cầu, tránh tưởng nhầm lỗi.
        $cancelledCheckoutRequest = $checkoutRequest
            ? null
            : CheckoutRequest::where('occupancy_id', $occupancy->id)
                ->where('status', 'cancelled')
                ->latest('id')
                ->first();

        // Support requests for this student
        $supportRequests = StudentSupportRequest::query()
            ->where('student_id', $occupancy->student_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StudentSupportRequest $r) => [
                'id'           => $r->id,
                'title'        => $r->title,
                'status'       => $r->status,
                'created_at'   => $r->created_at?->toDateString(),
            ])->values()->all();

        // Room change history — scoped to this student via occupancy join, exclude system-generated entries
        $roomChangeHistory = DB::table('room_change_log as rcl')
            ->join('occupancy as occ', 'occ.id', '=', 'rcl.occupancy_id')
            ->leftJoin('rooms as old_r', 'old_r.id', '=', 'rcl.old_room_id')
            ->leftJoin('floors as old_f', 'old_f.id', '=', 'old_r.floor_id')
            ->leftJoin('beds as old_b', 'old_b.id', '=', 'rcl.old_bed_id')
            ->leftJoin('rooms as new_r', 'new_r.id', '=', 'rcl.new_room_id')
            ->leftJoin('floors as new_f', 'new_f.id', '=', 'new_r.floor_id')
            ->leftJoin('beds as new_b', 'new_b.id', '=', 'rcl.new_bed_id')
            ->where('occ.student_id', $occupancy->student_id)
            ->whereNotIn('rcl.transfer_reason', ['assign_room', 'select_bed'])
            ->orderBy('rcl.transferred_at', 'asc')
            ->select([
                'rcl.id',
                'rcl.change_type',
                'rcl.change_source',
                'rcl.transfer_reason',
                'rcl.is_temporary',
                'rcl.transferred_at',
                DB::raw("NULLIF(CONCAT(COALESCE(old_f.building_code,''), COALESCE(old_r.room_number,'')), '') as old_room_code"),
                DB::raw("COALESCE(old_b.bed_number, NULL) as old_bed_number"),
                DB::raw("NULLIF(CONCAT(COALESCE(new_f.building_code,''), COALESCE(new_r.room_number,'')), '') as new_room_code"),
                DB::raw("COALESCE(new_b.bed_number, NULL) as new_bed_number"),
            ])
            ->get()
            ->map(fn ($row) => [
                'id'             => $row->id,
                'change_type'    => $row->change_type,
                'change_source'  => $row->change_source,
                'transfer_reason'=> $row->transfer_reason,
                'is_temporary'   => (bool) $row->is_temporary,
                'transferred_at' => $row->transferred_at
                    ? \Carbon\Carbon::parse($row->transferred_at)->toDateString()
                    : null,
                'old_room_code'  => $row->old_room_code,
                'old_bed_number' => $row->old_bed_number ? (string) $row->old_bed_number : null,
                'new_room_code'  => $row->new_room_code,
                'new_bed_number' => $row->new_bed_number ? (string) $row->new_bed_number : null,
            ])->values()->all();

        return [
            'student'             => $studentData,
            'family'              => $familyData,
            'occupancy_history'   => $historyItems,
            'recent_violations'   => $recentViolations,
            'current_invoice'     => $currentInvoice,
            'total_debt'          => $totalDebt,
            'unpaid_debt'         => $unpaidDebt,
            'checkout_request'    => $checkoutRequest ? [
                'id' => $checkoutRequest->id,
                'reason' => $checkoutRequest->reason,
                'expected_leave_date' => $checkoutRequest->expected_leave_date?->toDateString(),
                'created_at' => $checkoutRequest->created_at,
            ] : null,
            'cancelled_checkout_request' => $cancelledCheckoutRequest ? [
                'id' => $cancelledCheckoutRequest->id,
                'cancelled_at' => $cancelledCheckoutRequest->processed_at ?? $cancelledCheckoutRequest->updated_at,
            ] : null,
            'support_requests'    => $supportRequests,
            'room_change_history' => $roomChangeHistory,
        ];
    }

    private function resolveImageUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Một số bản ghi (vd. Registration được convert từ hồ sơ giữ chỗ tân sinh viên) đã có
        // sẵn prefix storage trong path lưu — phải strip trước, nếu không sẽ bị double-prefix
        // (vd. "/storage/api/storage/..."), ảnh không hiển thị được (404).
        $cleanPath    = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        return $isProduction
            ? url('/api/storage/' . $cleanPath)
            : url('/storage/' . $cleanPath);
    }
}
