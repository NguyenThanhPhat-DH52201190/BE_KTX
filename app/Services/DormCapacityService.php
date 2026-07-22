<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\OccupancyExtension;
use App\Models\OccupancyPeriod;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use Illuminate\Database\Eloquent\Builder;

class DormCapacityService
{
    /**
     * Nguồn DUY NHẤT tính "giường khả dụng vật lý" toàn KTX — không gắn với 1 đợt đăng ký
     * cụ thể nào (bed/room là tài nguyên global, dùng chung mọi đợt). Occupancy "hiệu lực"
     * (đang giữ giường, không được tính là khả dụng) LUÔN dùng đúng
     * Occupancy::OCCUPIED_BED_STATUSES (ROOM_CONFIRMED/PENDING_PAYMENT/ACTIVE) — không
     * hard-code lại danh sách trạng thái ở bất kỳ nơi nào khác (DashboardController phải
     * gọi lại đúng hàm này, không tự tính riêng — đây chính là nguyên nhân BUG-1: Dashboard
     * trước đây chỉ trừ Occupancy ACTIVE, lệch với DormCapacityService trừ cả ROOM_CONFIRMED/
     * PENDING_PAYMENT, khiến 2 nơi cùng nhãn "Giường khả dụng" hiển thị 2 số khác nhau).
     *
     * @return array{total_beds: int, maintenance_or_blocked_beds: int,
     *               physically_occupied_or_reserved_beds: int, available_physical_beds: int,
     *               available_physical_bed_ids: \Illuminate\Support\Collection}
     */
    public function summarizePhysicalBeds(): array
    {
        $usableBedIds = Bed::query()
            ->where('beds.status', 'active')
            ->whereHas('room', fn ($q) => $q->where('status', 'active'))
            ->pluck('id')
            ->unique()
            ->values();

        $totalBeds = Bed::query()->count();

        $maintenanceOrBlockedBeds = Bed::query()
            ->where(function ($query) {
                $query->where('beds.status', '!=', 'active')
                    ->orWhereHas('room', fn ($roomQuery) => $roomQuery->where('status', '!=', 'active'));
            })
            ->distinct('beds.id')
            ->count('beds.id');

        $occupiedBedIds = Occupancy::occupiedBedsQuery()
            ->whereIn('bed_id', $usableBedIds)
            ->pluck('bed_id')
            ->unique()
            ->values();

        $physicallyOccupiedOrReservedBeds = $occupiedBedIds->count();
        $availablePhysicalBedIds = $usableBedIds->diff($occupiedBedIds)->values();
        $availablePhysicalBeds = $availablePhysicalBedIds->count();

        return [
            'total_beds' => $totalBeds,
            'maintenance_or_blocked_beds' => $maintenanceOrBlockedBeds,
            'physically_occupied_or_reserved_beds' => $physicallyOccupiedOrReservedBeds,
            'available_physical_beds' => $availablePhysicalBeds,
            'available_physical_bed_ids' => $availablePhysicalBedIds,
        ];
    }

    /** Số giường vật lý khả dụng toàn KTX — dùng cho Dashboard (không cần scope theo đợt). */
    public function countAvailablePhysicalBeds(): int
    {
        return $this->summarizePhysicalBeds()['available_physical_beds'];
    }

    public function summarizeForRegistrationPeriod(
        RegistrationPeriod|int|null $period = null,
        int $proposedApprovedCount = 0,
        int $reservedBufferBeds = 0
    ): array {
        $registrationPeriod = $period instanceof RegistrationPeriod
            ? $period
            : ($period ? RegistrationPeriod::find($period) : null);

        $periodId = $registrationPeriod?->id;
        $occupancyPeriodId = $this->resolveOccupancyPeriodId($registrationPeriod);

        $physicalBeds = $this->summarizePhysicalBeds();
        $totalBeds = $physicalBeds['total_beds'];
        $maintenanceOrBlockedBeds = $physicalBeds['maintenance_or_blocked_beds'];
        $physicallyOccupiedOrReservedBeds = $physicalBeds['physically_occupied_or_reserved_beds'];
        $availablePhysicalBeds = $physicalBeds['available_physical_beds'];

        $occupiedStudentIds = Occupancy::occupiedBedsQuery()
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->unique()
            ->values();

        $approvedExtensions = 0;
        if ($occupancyPeriodId) {
            $approvedExtensions = OccupancyExtension::query()
                ->where('status', 'approved')
                ->where('occupancy_period_id', $occupancyPeriodId)
                ->whereNotIn('student_id', $occupiedStudentIds)
                ->distinct('student_id')
                ->count('student_id');
        }

        $approvedUnassignedRegistrations = 0;
        if ($periodId) {
            $approvedUnassignedRegistrations = Registration::query()
                ->where('registration_period_id', $periodId)
                ->where('status', 'approved')
                ->whereNotIn('student_id', $occupiedStudentIds)
                ->distinct('student_id')
                ->count('student_id');
        }

        $approvedDormReservations = 0;
        if ($periodId) {
            $approvedDormReservations = DormReservation::query()
                ->where('registration_period_id', $periodId)
                ->where('status', 'approved')
                ->whereNull('converted_registration_id')
                ->whereDoesntHave('candidate', function (Builder $candidateQuery) use ($periodId) {
                    // Chỉ loại DormReservation khỏi counter này khi candidate đã có Registration
                    // THẬT SỰ approved (đã được đếm bên approved_unassigned_registrations) —
                    // không dùng whereNotIn(['rejected','cancelled']) vì Registration đang
                    // 'submitted' (kể cả nguồn giữ chỗ chờ admin xác nhận, hoặc đăng ký thường
                    // chưa xử lý) KHÔNG tiêu tốn suất nào, loại sớm sẽ làm suất của
                    // DormReservation approved "biến mất" khỏi mọi bộ đếm trong lúc chờ.
                    $candidateQuery
                        ->whereNotNull('student_id')
                        ->whereHas('student.registrations', function (Builder $registrationQuery) use ($periodId) {
                            $registrationQuery
                                ->where('registration_period_id', $periodId)
                                ->where('status', 'approved');
                        });
                })
                ->distinct('admission_candidate_id')
                ->count('admission_candidate_id');
        }

        $availableApprovalSlots = max(
            0,
            $availablePhysicalBeds
                - $approvedExtensions
                - $approvedUnassignedRegistrations
                - $approvedDormReservations
                - $reservedBufferBeds
        );

        $remainingAfterProposals = max(0, $availableApprovalSlots - $proposedApprovedCount);
        $capacityExceeded = $proposedApprovedCount > $availableApprovalSlots;

        return [
            'total_beds' => $totalBeds,
            'maintenance_or_blocked_beds' => $maintenanceOrBlockedBeds,
            'physically_occupied_or_reserved_beds' => $physicallyOccupiedOrReservedBeds,
            'available_physical_beds' => $availablePhysicalBeds,
            'approved_extensions' => $approvedExtensions,
            'approved_unassigned_registrations' => $approvedUnassignedRegistrations,
            'approved_dorm_reservations' => $approvedDormReservations,
            'reserved_buffer_beds' => $reservedBufferBeds,
            'available_approval_slots' => $availableApprovalSlots,
            'proposed_approved_count' => $proposedApprovedCount,
            'remaining_after_proposals' => $remainingAfterProposals,
            'capacity_exceeded' => $capacityExceeded,
            'over_capacity_count' => $capacityExceeded ? $proposedApprovedCount - $availableApprovalSlots : 0,
        ];
    }

    private function resolveOccupancyPeriodId(?RegistrationPeriod $registrationPeriod): ?int
    {
        if ($registrationPeriod?->stay_end_date) {
            $matching = OccupancyPeriod::query()
                ->whereDate('extension_until_date', $registrationPeriod->stay_end_date)
                ->orderByDesc('end_date')
                ->orderByDesc('id')
                ->first();

            if ($matching) {
                return $matching->id;
            }
        }

        return OccupancyPeriod::query()
            ->where('status', 'closed')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->value('id');
    }
}
