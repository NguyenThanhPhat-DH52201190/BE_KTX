<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\Occupancy;
use App\Models\Registration;

class AutoReviewService
{
    /**
     * Called immediately after a new registration is created.
     *
     * Rolling channel: auto-approve if free beds exist, auto-reject if not.
     * Main channel: no action — admin triggers batch processing after period closes.
     */
    public function handleAfterSubmission(Registration $registration): void
    {
        $registration->loadMissing('period');
        $period = $registration->period;

        if (!$period || $period->channel !== 'rolling') {
            return;
        }

        // auto_decision là đề xuất cho admin, status KHÔNG thay đổi ở đây
        if ($this->countFreeBeds() > 0) {
            $registration->auto_decision = 'approve';
            $registration->auto_decision_reason = null;
        } else {
            $registration->auto_decision = 'reject';
            $registration->auto_decision_reason = 'Không còn giường trống phù hợp';
        }

        $registration->save();
    }

    private function countFreeBeds(): int
    {
        $totalAvailable = Bed::where('status', 'active')->count();
        $occupiedCount = Occupancy::occupiedBedsQuery()->pluck('bed_id')->unique()->count();

        return max(0, $totalAvailable - $occupiedCount);
    }
}
