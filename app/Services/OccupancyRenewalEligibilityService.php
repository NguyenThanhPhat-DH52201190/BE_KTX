<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Occupancy;

class OccupancyRenewalEligibilityService
{
    public function requiresAdminReview(Occupancy|int $occupancy): bool
    {
        $occupancyId = $occupancy instanceof Occupancy ? $occupancy->id : $occupancy;

        return $this->mediumNegativeActivityCount($occupancyId) > 1;
    }

    public function mediumNegativeActivityCount(Occupancy|int $occupancy): int
    {
        $occupancyId = $occupancy instanceof Occupancy ? $occupancy->id : $occupancy;

        return Activity::query()
            ->where('occupancy_id', $occupancyId)
            ->whereHas('type', function ($query) {
                $query
                    ->where('category', 'negative')
                    ->where('level', 'MEDIUM');
            })
            ->count();
    }
}
