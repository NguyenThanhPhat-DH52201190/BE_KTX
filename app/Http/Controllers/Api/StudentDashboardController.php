<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ElectricityBill;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\RoomFeeBill;
use App\Models\Student;
use App\Models\StudentSupportRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $student = Student::find($account->student_id);

        if (!$student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        // 1. Latest registration and current occupancy
        $latestRegistration = Registration::where('student_id', $student->id)
            ->with('occupancy')
            ->orderByDesc('created_at')
            ->first();

        $currentOccupancy = null;
        $occupancyModel   = null;

        $registration = Registration::where('student_id', $student->id)
            ->where('status', 'approved')
            ->with(['occupancy' => fn ($q) => $q->with(['room.floor', 'bed'])])
            ->orderByDesc('created_at')
            ->first();

        if ($registration?->occupancy &&
            in_array($registration->occupancy->status, ['ACTIVE', 'ROOM_CONFIRMED'])) {
            $occ            = $registration->occupancy;
            $occupancyModel = $occ;
            $floor          = $occ->room?->floor;

            $checkIn  = $occ->check_in_date  ?? $registration->stay_from_date;
            $checkOut = $occ->check_out_date ?? $registration->stay_to_date;

            $totalDays = ($checkIn && $checkOut)
                ? (int) Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut))
                : null;
            $daysLeft = $checkOut
                ? (int) max(0, now()->diffInDays(Carbon::parse($checkOut), false))
                : null;

            $currentOccupancy = [
                'id'             => $occ->id,
                'status'         => $occ->status,
                'check_in_date'  => $checkIn,
                'check_out_date' => $checkOut,
                'total_days'     => $totalDays,
                'days_left'      => $daysLeft,
                'room'           => $occ->room ? [
                    'building_code' => $floor?->building_code,
                    'floor_number'  => $floor?->floor_number,
                    'room_number'   => $occ->room->room_number,
                ] : null,
                'bed'            => $occ->bed ? [
                    'bed_number' => $occ->bed->bed_number,
                ] : null,
            ];
        }

        // 2. Current invoice (this month)
        $currentInvoice = null;

        if ($occupancyModel) {
            $now   = now();
            $month = $now->month;
            $year  = $now->year;

            $roomFee = RoomFeeBill::where('occupancy_id', $occupancyModel->id)
                ->where('month', $month)
                ->where('year', $year)
                ->first();

            $elecBill = ElectricityBill::where('occupancy_id', $occupancyModel->id)
                ->whereMonth('due_date', $month)
                ->whereYear('due_date', $year)
                ->first();

            $overdueRoom = (int) RoomFeeBill::where('occupancy_id', $occupancyModel->id)
                ->where('status', 'overdue')
                ->sum('amount');

            $overdueElec = (int) ElectricityBill::where('occupancy_id', $occupancyModel->id)
                ->where('status', 'overdue')
                ->sum('amount');

            $currentInvoice = [
                'room_fee'       => $roomFee ? [
                    'amount' => (int) $roomFee->amount,
                    'status' => $roomFee->status,
                    'period' => sprintf('%02d/%d', $roomFee->month, $roomFee->year),
                ] : null,
                'electricity'    => $elecBill ? [
                    'amount'    => (int) $elecBill->amount,
                    'status'    => $elecBill->status,
                    'usage_kwh' => $elecBill->usage_kwh,
                ] : null,
                'overdue_amount' => $overdueRoom + $overdueElec,
            ];
        }

        // 3. Recent notifications (4)
        $notifications = DB::table('notification_recipient as nr')
            ->join('notifications as n', 'n.id', '=', 'nr.notification_id')
            ->where('nr.student_id', $student->id)
            ->orderByDesc('n.created_at')
            ->limit(4)
            ->select(['nr.id as recipient_id', 'n.id', 'n.title', 'n.content', 'n.type', 'n.related_id', 'n.target_type', 'n.created_at', 'nr.is_read'])
            ->get();

        // 4. Pending support requests
        $pendingRequests = StudentSupportRequest::where('student_id', $student->id)
            ->whereIn('status', ['pending', 'processing'])
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'status', 'created_at']);

        // 5. Recent activities (5)
        $recentActivities = collect();
        if ($occupancyModel) {
            $recentActivities = Activity::with('type')
                ->where('occupancy_id', $occupancyModel->id)
                ->orderByDesc('activity_date')
                ->limit(5)
                ->get()
                ->map(fn ($a) => [
                    'id'           => $a->id,
                    'date'         => $a->activity_date,
                    'note'         => $a->note,
                    'action_taken' => $a->action_taken,
                    'type'         => $a->type ? [
                        'name'     => $a->type->name,
                        'category' => $a->type->category,
                        'level'    => $a->type->level,
                    ] : null,
                ]);
        }

        // 6. Active registration period
        $activePeriod = RegistrationPeriod::where('status', 'active')
            ->orderByDesc('created_at')
            ->first(['id', 'name', 'end_date']);

        return response()->json([
            'student'                    => [
                'id'           => $student->id,
                'student_code' => $student->student_code,
                'full_name'    => $student->full_name,
                'email'        => $student->email,
                'avatar'       => $student->avatar,
                'class_name'   => $student->class_name,
                'faculty'      => $student->faculty,
            ],
            'current_occupancy'          => $currentOccupancy,
            'current_invoice'            => $currentInvoice,
            'latest_registration'         => $latestRegistration ? [
                'id'                  => $latestRegistration->id,
                'status'              => $latestRegistration->status,
                'rejection_reason'    => $latestRegistration->rejection_reason,
                'assigned_room_id'    => $latestRegistration->occupancy?->room_id,
                'bed_id'              => $latestRegistration->occupancy?->bed_id,
                'bed_approval_status' => $latestRegistration->occupancy?->bed_approval_status,
                'occupancy_status'    => $latestRegistration->occupancy?->status,
            ] : null,
            'notifications'              => $notifications,
            'pending_requests'           => $pendingRequests,
            'recent_activities'          => $recentActivities->values(),
            'active_registration_period' => $activePeriod ? [
                'id'        => $activePeriod->id,
                'name'      => $activePeriod->name,
                'end_date'  => $activePeriod->end_date,
                'days_left' => (int) max(0, now()->diffInDays(Carbon::parse($activePeriod->end_date), false)),
            ] : null,
        ]);
    }
}
