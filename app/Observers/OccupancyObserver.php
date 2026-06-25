<?php

namespace App\Observers;

use App\Models\Occupancy;
use App\Services\RoomFeeBillingService;

class OccupancyObserver
{
    public function __construct(private RoomFeeBillingService $billingService) {}

    /**
     * Khi admin tạo occupancy thẳng với ACTIVE (hiếm) → sinh hóa đơn.
     */
    public function created(Occupancy $occupancy): void
    {
        if ($occupancy->status === 'ACTIVE') {
            $this->billingService->createBillsOnActivation($occupancy);
        }
    }

    /**
     * Khi occupancy chuyển sang ACTIVE:
     * - Luồng sinh viên (PENDING_PAYMENT → ACTIVE qua VNPay): hóa đơn đã có,
     *   createBillsOnActivation() bỏ qua nhờ idempotency check.
     * - Luồng admin (ROOM_CONFIRMED → ACTIVE qua approveBed): chưa có hóa đơn,
     *   sinh tại đây.
     */
    public function updated(Occupancy $occupancy): void
    {
        if ($occupancy->wasChanged('status') && $occupancy->status === 'ACTIVE') {
            $this->billingService->createBillsOnActivation($occupancy);
        }
    }
}
