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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
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

        // Available beds: beds with status='active' that have no ACTIVE occupancy assigned
        $availableBeds = Bed::where('status', 'active')
            ->whereNotIn('id', function ($query) {
                $query->select('bed_id')
                    ->from('occupancy')
                    ->where('status', 'ACTIVE')
                    ->whereNotNull('bed_id');
            })
            ->count();

        $maintenanceBeds = Bed::where('status', 'maintenance')->count();

        // ── 2. Alerts ─────────────────────────────────────────────────────
        $pendingRegistrations = Registration::where('status', 'submitted')->count();

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

        // ── 5. Finance this month ─────────────────────────────────────────
        $now = Carbon::now();
        $finance = $this->financePayload($now->month, $now->year);

        return response()->json([
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
                'expiring_occupancies_30d' => $expiringOccupancies,
                'pending_bed_selection'    => $pendingBedSelection,
                'pending_room_changes'      => $pendingRoomChanges,
                'pending_bed_changes'       => $pendingBedChanges,
                'pending_checkouts'         => $pendingCheckouts,
                'pending_support_requests'  => $pendingSupportRequests,
            ],
            'current_period' => $periodData,
            'charts' => [
                'by_faculty'  => $byFaculty,
                'by_year'     => $byYear,
                'by_province' => $byProvince,
                'by_gender'   => $byGender,
            ],
            'finance' => $finance,
        ]);
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
