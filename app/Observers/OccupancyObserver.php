<?php

namespace App\Observers;

use App\Models\Occupancy;
use App\Services\RoomFeeBillingService;

class OccupancyObserver
{
    public function __construct(private RoomFeeBillingService $billingService) {}

    /**
     * Xử lý khi occupancy được tạo mới với status = ACTIVE.
     * (Ít gặp nhưng cần cover để tránh bỏ sót hóa đơn.)
     */
    public function created(Occupancy $occupancy): void
    {
        if ($occupancy->status === 'ACTIVE') {
            $this->billingService->createBillsOnActivation($occupancy);
        }
    }

    /**
     * Xử lý khi occupancy chuyển sang ACTIVE (PROPOSED/ROOM_CONFIRMED -> ACTIVE).
     */
    public function updated(Occupancy $occupancy): void
    {
        if ($occupancy->wasChanged('status') && $occupancy->status === 'ACTIVE') {
            $this->billingService->createBillsOnActivation($occupancy);
        }
    }
}
