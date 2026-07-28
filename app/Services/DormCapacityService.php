<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\OccupancyExtension;
use App\Models\OccupancyPeriod;
use App\Models\Registration;
use App\Models\RegistrationPeriod;

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
    /**
     * Lọc theo giới tính của TẦNG chứa phòng/giường — không phải Room/Bed (giới tính gán ở
     * cấp Floor, xem BuildingController::normalizeGender()). Tầng KHÔNG gán giới tính
     * (null/rỗng) được coi là "dùng chung", tính vào CẢ 2 giới — đúng quy ước đã có sẵn ở
     * AutoRoomAssignmentService::pickRoom() (`info['gender'] === $gender || info['gender']
     * === ''`), không tự bịa quy ước khác gây lệch với chỗ xếp phòng thật.
     */
    private function applyFloorGenderFilter($query, ?string $gender): void
    {
        if ($gender === null) {
            return;
        }

        $query->whereHas('room.floor', function ($q) use ($gender) {
            $q->where(function ($qq) use ($gender) {
                $qq->whereNull('gender')->orWhere('gender', '')->orWhere('gender', $gender);
            });
        });
    }

    /**
     * @param string|null $gender 'male'|'female' — lọc giường theo giới tính tầng (null = gộp
     *                            chung toàn KTX, giữ hành vi cũ cho nơi chưa cần tách giới).
     */
    public function summarizePhysicalBeds(?string $gender = null): array
    {
        $usableBedsQuery = Bed::query()
            ->where('beds.status', 'active')
            ->whereHas('room', fn ($q) => $q->where('status', 'active'));
        $this->applyFloorGenderFilter($usableBedsQuery, $gender);
        $usableBedIds = $usableBedsQuery->pluck('id')->unique()->values();

        $totalBedsQuery = Bed::query();
        $this->applyFloorGenderFilter($totalBedsQuery, $gender);
        $totalBeds = $totalBedsQuery->count();

        $maintenanceOrBlockedQuery = Bed::query()
            ->where(function ($query) {
                $query->where('beds.status', '!=', 'active')
                    ->orWhereHas('room', fn ($roomQuery) => $roomQuery->where('status', '!=', 'active'));
            });
        $this->applyFloorGenderFilter($maintenanceOrBlockedQuery, $gender);
        $maintenanceOrBlockedBeds = $maintenanceOrBlockedQuery->distinct('beds.id')->count('beds.id');

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

    /**
     * @param string|null $gender 'male'|'female' — tính suất trống RIÊNG cho giới tính này
     *                            (giường, sinh viên gia hạn, đơn approved, hồ sơ giữ chỗ đều
     *                            lọc theo đúng giới). null = gộp chung toàn KTX (hành vi cũ,
     *                            chỉ còn dùng cho các nơi cố tình muốn xem tổng quan không
     *                            phân biệt giới, KHÔNG dùng để quyết định duyệt/xác nhận nữa
     *                            — xem báo cáo 27/07: duyệt theo tổng gộp gây vượt suất 1 giới
     *                            trong khi giới kia còn dư, vì phòng/giường vốn tách theo giới
     *                            tính ở cấp Floor).
     * @param bool $excludeReservationsWithRegistration CHỈ dùng khi tính ngân sách cho BƯỚC
     *             XẾP HẠNG (`RegistrationPeriodController::process()`). Hồ sơ giữ chỗ đã có
     *             Registration nguồn (dù đang 'submitted' chờ xác nhận) chính là NGƯỜI ĐANG
     *             ĐƯỢC XẾP HẠNG trong cùng bảng — KHÔNG được trừ suất họ trước rồi lại bắt họ
     *             cạnh tranh phần còn lại, sẽ thành trừ 2 lần cùng 1 suất (báo cáo 28/07: suất
     *             nữ tính ra 0 dù 2 người đang xếp hạng chính là 2 người giữ 2 giường nữ đó).
     *             Chỉ hồ sơ CHƯA có Registration (chưa nhập học, đứng NGOÀI bảng xếp hạng, như
     *             1 candidate approved-giữ-chỗ nhưng chưa từng nộp đơn) mới cần trừ trước ở
     *             đây. Mặc định false — giữ hành vi "trừ đủ mọi approved reservation" cho các
     *             chỗ duyệt/xác nhận 1 hồ sơ lẻ (không qua xếp hạng lại).
     */
    public function summarizeForRegistrationPeriod(
        RegistrationPeriod|int|null $period = null,
        int $proposedApprovedCount = 0,
        int $reservedBufferBeds = 0,
        ?string $gender = null,
        bool $excludeReservationsWithRegistration = false
    ): array {
        $registrationPeriod = $period instanceof RegistrationPeriod
            ? $period
            : ($period ? RegistrationPeriod::find($period) : null);

        $periodId = $registrationPeriod?->id;
        $occupancyPeriodId = $this->resolveOccupancyPeriodId($registrationPeriod);

        $physicalBeds = $this->summarizePhysicalBeds($gender);
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
                ->when($gender !== null, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('gender', $gender)))
                ->distinct('student_id')
                ->count('student_id');
        }

        $approvedUnassignedRegistrations = 0;
        if ($periodId) {
            $approvedUnassignedRegistrations = Registration::query()
                ->where('registration_period_id', $periodId)
                ->where('status', 'approved')
                ->whereNotIn('student_id', $occupiedStudentIds)
                ->when($gender !== null, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('gender', $gender)))
                ->distinct('student_id')
                ->count('student_id');
        }

        $approvedDormReservations = 0;
        if ($periodId) {
            // Trừ suất của MỌI hồ sơ giữ chỗ đang 'approved' — bất kể candidate đã nhập học
            // (có Registration nguồn) hay chưa. Trước đây có loại trừ hồ sơ đã có Registration
            // qua whereDoesntHave('convertedIntoRegistration'), với lý do "suất của họ đã được
            // đếm bên Registration rồi, trừ ở đây là trừ 2 lần". NHƯNG Registration chỉ thật
            // sự được đếm ở approved_unassigned_registrations khi đã status='approved' — mà
            // ngay khi Registration chuyển 'approved', DormReservation nguồn CŨNG đồng thời
            // chuyển 'converted' trong CÙNG transaction (xem RegistrationController::
            // confirmSingle()/confirmBatch()/approve()), nên đã tự động rời khỏi điều kiện
            // where('status','approved') ở trên rồi — không cần loại trừ thêm nữa. Trong khi
            // đó, hồ sơ đã có Registration nhưng Registration còn 'submitted' (CHỜ admin xác
            // nhận) lại bị whereDoesntHave loại nhầm — không được đếm ở ĐÂY (loại vì có
            // Registration) mà cũng không được đếm ở approved_unassigned_registrations (chỉ
            // đếm status='approved', 'submitted' thì chưa) — suất của họ "biến mất" khỏi cả 2
            // bộ đếm, hệ thống tưởng còn dư suất, cho duyệt vượt quá số giường thật (báo cáo
            // 28/07: 3 hồ sơ nữ cùng 'approved' dù chỉ có 2 giường nữ). Bỏ hẳn điều kiện loại
            // trừ để không còn khoảng trống này.
            $approvedDormReservations = DormReservation::query()
                ->where('registration_period_id', $periodId)
                ->where('status', 'approved')
                ->when($excludeReservationsWithRegistration, fn ($q) => $q->whereDoesntHave('convertedIntoRegistration'))
                ->when($gender !== null, fn ($q) => $q->whereHas('candidate', fn ($cq) => $cq->where('gender', $gender)))
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
