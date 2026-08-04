<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\CheckoutRequest;
use App\Models\ElectricityBill;
use App\Models\Occupancy;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\Room;
use App\Models\RoomFeeBill;
use App\Models\Student;
use App\Models\StudentSupportRequest;
use App\Services\DormCapacityService;
use App\Services\ExcelService;
use App\Services\PdfService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        $payload = $this->buildIndexPayload();

        $now = Carbon::now();
        $payload['finance'] = $this->financePayload($now->month, $now->year);

        return response()->json($payload);
    }

    public function exportPdf(Request $request, PdfService $pdfService): Response
    {
        $now = Carbon::now();
        $data = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $month = (int) ($data['month'] ?? $now->month);
        $year = (int) ($data['year'] ?? $now->year);

        $payload = $this->buildIndexPayload();
        $payload['finance'] = $this->financePayload($month, $year);

        return $pdfService->download(
            'pdf.dashboard-report',
            $payload,
            'bao_cao_thong_ke_' . $month . '_' . $year . '.pdf',
            ['orientation' => 'landscape'],
        );
    }

    public function exportExcel(Request $request, ExcelService $excelService): Response
    {
        $now = Carbon::now();
        $data = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $month = (int) ($data['month'] ?? $now->month);
        $year = (int) ($data['year'] ?? $now->year);

        $payload = $this->buildIndexPayload();
        $finance = $this->financePayload($month, $year);
        $stats = $payload['stats'];
        $alerts = $payload['alerts'];

        $overviewRows = [
            ['Tổng sinh viên đang ở', $stats['total_students']],
            ['Tổng giường', $stats['total_beds']],
            ['Sinh viên nam', $stats['male_count']],
            ['Sinh viên nữ', $stats['female_count']],
            ['Phòng đang sử dụng', $stats['occupied_rooms']],
            ['Tổng số phòng', $stats['total_rooms']],
            ['Giường đã ở', $stats['occupied_beds']],
            ['Giường khả dụng', $stats['available_beds']],
            ['Giường bảo trì', $stats['maintenance_beds']],
            ['Đơn đăng ký chờ duyệt', $stats['pending_registrations']],
            ['Hóa đơn quá hạn', $alerts['overdue_invoices']],
            ['Lưu trú sắp hết hạn (30 ngày)', $alerts['expiring_occupancies_30d']],
            ['Chờ chọn giường', $alerts['pending_bed_selection']],
            ['Yêu cầu đổi phòng chờ duyệt', $alerts['pending_room_changes']],
            ['Yêu cầu đổi giường chờ duyệt', $alerts['pending_bed_changes']],
            ['Yêu cầu thôi ở chờ duyệt', $alerts['pending_checkouts']],
            ['Yêu cầu hỗ trợ chờ xử lý', $alerts['pending_support_requests']],
        ];

        $financeRows = [
            ['Tiền phòng', $finance['room_expected_total'], $finance['room_collected'], $finance['room_debt']],
            ['Tiền điện', $finance['electricity_expected_total'], $finance['electricity_collected'], $finance['electricity_debt']],
            ['Tổng cộng (' . $finance['debtors_count'] . ' sinh viên còn nợ)', $finance['expected_total'], $finance['collected'], $finance['debt']],
        ];

        $facultyRows = collect($payload['charts']['by_faculty'])->values()->map(fn (array $r) => [$r['faculty'], $r['male'], $r['female'], $r['total']])->all();
        $yearRows = collect($payload['charts']['by_year'])->values()->map(fn (array $r) => ['Năm ' . $r['year'], $r['male'], $r['female'], $r['total']])->all();
        $provinceRows = collect($payload['charts']['by_province'])->values()->map(fn (array $r) => [$r['province'], $r['count']])->all();

        return $excelService->download(
            'bao_cao_thong_ke_' . $month . '_' . $year . '.xlsx',
            [
                ['title' => 'Tong quan', 'headers' => ['Chỉ số', 'Giá trị'], 'rows' => $overviewRows],
                ['title' => 'Tai chinh', 'headers' => ['Khoản mục', 'Dự kiến thu', 'Đã thu', 'Còn nợ'], 'rows' => $financeRows],
                ['title' => 'Theo khoa', 'headers' => ['Khoa', 'Nam', 'Nữ', 'Tổng'], 'rows' => $facultyRows],
                ['title' => 'Theo nam hoc', 'headers' => ['Năm', 'Nam', 'Nữ', 'Tổng'], 'rows' => $yearRows],
                ['title' => 'Theo tinh thanh', 'headers' => ['Tỉnh/Thành', 'Số lượng'], 'rows' => $provinceRows],
            ],
        );
    }

    private function buildIndexPayload(): array
    {
        // ── 1. Stat cards ─────────────────────────────────────────────────
        $totalStudents = Occupancy::where('status', 'ACTIVE')
            ->where('bed_approval_status', 'approved')
            ->count();

        $totalBeds = Bed::count();
        $totalCapacity = $totalBeds;

        $maleCount = Occupancy::where('occupancy.status', 'ACTIVE')
            ->where('occupancy.bed_approval_status', 'approved')
            ->join('students', 'occupancy.student_id', '=', 'students.id')
            ->where('students.gender', 'male')
            ->count('occupancy.id');

        $femaleCount = $totalStudents - $maleCount;

        $occupiedRooms = Occupancy::where('status', 'ACTIVE')
            ->where('bed_approval_status', 'approved')
            ->whereNotNull('room_id')
            ->distinct('room_id')
            ->count('room_id');

        $totalRooms = Room::count();
        $occupiedBeds = Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('bed_id')
            ->distinct('bed_id')
            ->count('bed_id');

        // Giường khả dụng — dùng chung nguồn duy nhất với DormCapacityService (BUG-1: trước
        // đây Dashboard chỉ trừ Occupancy ACTIVE, lệch với Capacity UI trừ cả ROOM_CONFIRMED/
        // PENDING_PAYMENT, khiến cùng nhãn "Giường khả dụng" hiển thị 2 số khác nhau).
        $availableBeds = app(DormCapacityService::class)->countAvailablePhysicalBeds();

        $maintenanceBeds = Bed::where('status', 'maintenance')->count();

        // ── 2. Alerts ─────────────────────────────────────────────────────
        $pendingRegistrations = Registration::where('status', 'submitted')->count();

        // Breakdown theo kênh (main/rolling) + loại (pending chờ xử lý theo lô / review cần
        // duyệt tay) để FE điều hướng đúng tab khi bấm vào cảnh báo "Đơn chờ duyệt tay" —
        // không đổi ý nghĩa của $pendingRegistrations (tổng số), chỉ bổ sung thông tin định vị.
        $submittedRegs = Registration::where('status', 'submitted')
            ->with('period:id,channel')
            ->get(['id', 'registration_period_id', 'auto_decision']);

        $pendingPriorityRegIds = DB::table('student_priority')
            ->where('status', 'pending')
            ->distinct()
            ->pluck('registration_id')
            ->flip();

        $pendingRegistrationsBreakdown = [
            'main'    => ['pending' => 0, 'review' => 0],
            'rolling' => ['pending' => 0, 'review' => 0],
        ];

        foreach ($submittedRegs as $reg) {
            $channel = $reg->period?->channel === 'rolling' ? 'rolling' : 'main';
            $isReview = $reg->auto_decision === 'review' || $pendingPriorityRegIds->has($reg->id);
            $pendingRegistrationsBreakdown[$channel][$isReview ? 'review' : 'pending']++;
        }

        $expiringOccupancies = Occupancy::where('status', 'ACTIVE')
            ->whereNotNull('check_out_date')
            ->whereBetween('check_out_date', [
                Carbon::now()->toDateString(),
                Carbon::now()->addDays(30)->toDateString(),
            ])->count();

        $pendingBedSelection = Occupancy::where('status', 'ROOM_CONFIRMED')->count();

        $overdueInvoices = RoomFeeBill::where('status', 'overdue')->count()
            + ElectricityBill::where('status', 'overdue')->count();

        $pendingRoomChanges = DB::table('room_change_requests')
            ->where('status', 'pending')
            ->where('change_type', 'room')
            ->count();

        $pendingBedChanges = DB::table('room_change_requests')
            ->where('status', 'pending')
            ->where('change_type', 'bed')
            ->count();

        $pendingCheckouts = CheckoutRequest::where('status', 'pending')->count();

        $pendingSupportRequests = StudentSupportRequest::whereIn('status', ['pending', 'processing'])->count();

        // Theo dõi hàng đợi email (queue) — để phát hiện sớm nếu queue worker (Windows Service)
        // bị dừng mà không ai để ý, tránh email "biến mất" âm thầm.
        $queuePendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $queueFailedJobs  = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        // ── 3. Current period ─────────────────────────────────────────────
        $period = RegistrationPeriod::whereIn('status', ['active', 'processing', 'pending'])
            ->orderByRaw("FIELD(status, 'active', 'processing', 'pending')")
            ->first();

        $periodData = null;
        if ($period) {
            $regStats = Registration::where('registration_period_id', $period->id)
                ->selectRaw("status, COUNT(*) as cnt")
                ->groupBy('status')
                ->pluck('cnt', 'status');

            $periodRegIds = Registration::where('registration_period_id', $period->id)
                ->select('id');

            $oStats = Occupancy::whereIn('registration_id', $periodRegIds)
                ->selectRaw("status, COUNT(*) as cnt")
                ->groupBy('status')
                ->pluck('cnt', 'status');

            $approvedCount  = (int) ($regStats['approved'] ?? 0);
            $proposedCount  = (int) ($oStats['PROPOSED'] ?? 0);
            $confirmedCount = (int) ($oStats['ROOM_CONFIRMED'] ?? 0);
            $activeCount    = (int) ($oStats['ACTIVE'] ?? 0);
            $notAssigned    = max(0, $approvedCount - $proposedCount - $confirmedCount - $activeCount);

            $daysLeft = null;
            if ($period->status === 'active' && $period->end_date) {
                $end      = Carbon::parse($period->end_date)->endOfDay();
                $daysLeft = max(0, (int) Carbon::now()->diffInDays($end, false));
            }

            $periodData = [
                'id'          => $period->id,
                'name'        => $period->name,
                'status'      => $period->status,
                'channel'     => $period->channel,
                'school_year' => $period->school_year,
                'semester'    => $period->semester,
                'start_date'  => $period->start_date,
                'end_date'    => $period->end_date,
                'days_left'   => $daysLeft,
                'registrations' => [
                    'total'    => (int) $regStats->sum(),
                    'pending'  => (int) ($regStats['pending'] ?? 0),
                    'approved' => $approvedCount,
                    'rejected' => (int) ($regStats['rejected'] ?? 0),
                ],
                'room_assignment' => [
                    'not_assigned'   => $notAssigned,
                    'proposed'       => $proposedCount,
                    'room_confirmed' => $confirmedCount,
                    'active'         => $activeCount,
                ],
            ];
        }

        // ── 4. Charts — active occupants only ────────────────────────────
        $activeSubQuery = Occupancy::whereRaw('LOWER(status) = ?', ['active'])
            ->distinct()
            ->select('student_id');

        $byFaculty = Student::whereIn('id', $activeSubQuery)
            ->whereNotNull('faculty')
            ->selectRaw("
                faculty,
                SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) AS male,
                SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) AS female,
                COUNT(*) AS total
            ")
            ->groupBy('faculty')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'faculty' => $r->faculty,
                'male' => (int) $r->male,
                'female' => (int) $r->female,
                'total' => (int) $r->total,
            ])
            ->values();

        $byYear = Student::whereIn('id', $activeSubQuery)
            ->whereNotNull('current_year')
            ->selectRaw("
                current_year AS yr,
                SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) AS male,
                SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) AS female,
                COUNT(*) AS total
            ")
            ->groupBy('current_year')
            ->orderBy('current_year')
            ->get()
            ->map(fn ($r) => [
                'year' => (int) $r->yr,
                'male' => (int) $r->male,
                'female' => (int) $r->female,
                'total' => (int) $r->total,
            ])
            ->values();

        $byProvince = Student::whereIn('students.id', $activeSubQuery)
            ->join('provinces', 'students.province_code', '=', 'provinces.code')
            ->whereNotNull('students.province_code')
            ->selectRaw("provinces.name AS pname, COUNT(*) AS `count`")
            ->groupBy('provinces.name')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['province' => $r->pname, 'count' => (int) $r->count])
            ->values();

        $byGender = collect([
            ['gender' => 'Nam', 'count' => $maleCount],
            ['gender' => 'Nữ', 'count' => $femaleCount],
        ]);

        return [
            'stats' => [
                'total_students' => $totalStudents,
                'total_capacity' => $totalCapacity,
                'male_count'     => $maleCount,
                'female_count'   => $femaleCount,
                'pending_registrations' => $pendingRegistrations,
                'occupied_rooms' => $occupiedRooms,
                'total_rooms'    => $totalRooms,
                'total_beds'     => $totalBeds,
                'occupied_beds'  => $occupiedBeds,
                'available_beds'     => $availableBeds,
                'maintenance_beds'  => $maintenanceBeds,
            ],
            'alerts' => [
                'overdue_invoices'         => $overdueInvoices,
                'pending_registrations'    => $pendingRegistrations,
                'pending_registrations_breakdown' => $pendingRegistrationsBreakdown,
                'expiring_occupancies_30d' => $expiringOccupancies,
                'pending_bed_selection'    => $pendingBedSelection,
                'pending_room_changes'      => $pendingRoomChanges,
                'pending_bed_changes'       => $pendingBedChanges,
                'pending_checkouts'         => $pendingCheckouts,
                'pending_support_requests'  => $pendingSupportRequests,
            ],
            'queue' => [
                'pending_jobs' => $queuePendingJobs,
                'failed_jobs'  => $queueFailedJobs,
            ],
            'current_period' => $periodData,
            'charts' => [
                'by_faculty'  => $byFaculty,
                'by_year'     => $byYear,
                'by_province' => $byProvince,
                'by_gender'   => $byGender,
            ],
        ];
    }

    public function finance(Request $request)
    {
        $now = Carbon::now();
        $data = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $month = (int) ($data['month'] ?? $now->month);
        $year = (int) ($data['year'] ?? $now->year);

        return response()->json($this->financePayload($month, $year));
    }

    public function revenueTrend(Request $request)
    {
        $now = Carbon::now();
        $data = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $month = (int) ($data['month'] ?? $now->month);
        $year = (int) ($data['year'] ?? $now->year);

        return response()->json($this->revenueTrendPayload($month, $year));
    }

    private function revenueTrendPayload(int $month, int $year): array
    {
        $endMonth = Carbon::create($year, $month, 1)->startOfMonth();

        return collect(range(5, 0))
            ->map(function (int $offset) use ($endMonth) {
                $target = (clone $endMonth)->subMonths($offset);
                $targetMonth = (int) $target->month;
                $targetYear = (int) $target->year;
                $monthYear = $target->format('Y-m');

                $roomTotal = (float) RoomFeeBill::where('month', $targetMonth)
                    ->where('year', $targetYear)
                    ->sum('amount');

                $electricityTotal = (float) ElectricityBill::where('month_year', $monthYear)
                    ->sum('amount');

                return [
                    'month' => $targetMonth,
                    'year' => $targetYear,
                    'label' => $target->format('m/Y'),
                    'short_label' => 'T' . $targetMonth,
                    'room_total' => $roomTotal,
                    'electricity_total' => $electricityTotal,
                    'total' => $roomTotal + $electricityTotal,
                ];
            })
            ->values()
            ->all();
    }

    private function financePayload(int $month, int $year): array
    {
        $roomFinBase = RoomFeeBill::where('month', $month)->where('year', $year);
        $electricityFinBase = ElectricityBill::where('month_year', sprintf('%04d-%02d', $year, $month));

        $roomFinStats = (clone $roomFinBase)
            ->selectRaw("
                COALESCE(SUM(amount), 0)                                                         AS expected_total,
                COALESCE(SUM(CASE WHEN status = 'paid'              THEN amount ELSE 0 END), 0)  AS collected,
                COALESCE(SUM(CASE WHEN status IN ('unpaid','overdue') THEN amount ELSE 0 END), 0) AS debt
            ")
            ->first();

        $electricityFinStats = (clone $electricityFinBase)
            ->selectRaw("
                COALESCE(SUM(amount), 0)                                                         AS expected_total,
                COALESCE(SUM(CASE WHEN status = 'paid'              THEN amount ELSE 0 END), 0)  AS collected,
                COALESCE(SUM(CASE WHEN status IN ('unpaid','overdue') THEN amount ELSE 0 END), 0) AS debt
            ")
            ->first();

        $roomDebtorIds = (clone $roomFinBase)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->pluck('student_id');

        $electricityDebtorIds = (clone $electricityFinBase)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->pluck('student_id');

        $debtorsCount = $roomDebtorIds
            ->merge($electricityDebtorIds)
            ->filter()
            ->unique()
            ->count();

        $roomYears = RoomFeeBill::query()
            ->select('year')
            ->distinct()
            ->pluck('year');

        $electricityYears = ElectricityBill::query()
            ->selectRaw("CAST(SUBSTRING(month_year, 1, 4) AS UNSIGNED) AS year")
            ->distinct()
            ->pluck('year');

        $availableYears = $roomYears
            ->merge($electricityYears)
            ->filter()
            ->map(fn ($item) => (int) $item)
            ->merge([2024, 2025, 2026, 2027, 2028])
            ->unique()
            ->sort()
            ->values();

        $expectedTotal = (float) $roomFinStats->expected_total + (float) $electricityFinStats->expected_total;
        $collectedTotal = (float) $roomFinStats->collected + (float) $electricityFinStats->collected;
        $debtTotal = (float) $roomFinStats->debt + (float) $electricityFinStats->debt;

        return [
            'month'                       => $month,
            'year'                        => $year,
            'available_years'             => $availableYears,
            'expected_total'              => $expectedTotal,
            'collected'                   => $collectedTotal,
            'debt'                        => $debtTotal,
            'debtors_count'               => $debtorsCount,
            'room_expected_total'         => (float) $roomFinStats->expected_total,
            'room_collected'              => (float) $roomFinStats->collected,
            'room_debt'                   => (float) $roomFinStats->debt,
            'electricity_expected_total'  => (float) $electricityFinStats->expected_total,
            'electricity_collected'       => (float) $electricityFinStats->collected,
            'electricity_debt'            => (float) $electricityFinStats->debt,
        ];
    }
}
