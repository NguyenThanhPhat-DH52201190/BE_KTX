<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\StudentPriority;
use Illuminate\Support\Collection;

/**
 * Tiered priority ranking for dormitory registrations.
 *
 * Rule (must NOT mix scores across tiers):
 *   1. Compare the highest tier reached (min tier over VERIFIED criteria).
 *      Smaller tier wins. No verified priority => tier 99 (lowest).
 *   2. Same top tier => higher total score (sum of verified scores) wins.
 *   3. Still equal => earlier submission (created_at) wins.
 *
 * Score is only used to break ties WITHIN the same tier; it can never let a
 * lower-tier applicant jump above a higher-tier one.
 */
class PriorityRankingService
{
    /** Applicants with no verified priority sit at the lowest tier. */
    public const NO_PRIORITY_TIER = 99;

    /**
     * Compute top_priority_tier (min tier) and total_priority_score (sum) from
     * the student's VERIFIED priority criteria, then persist onto the
     * registration. Returns the computed values.
     *
     * @return array{top_priority_tier: int, total_priority_score: int}
     */
    public function calculateForRegistration(Registration $registration): array
    {
        $rows = StudentPriority::query()
            ->join('priority_criteria', 'student_priority.priority_criteria_id', '=', 'priority_criteria.id')
            ->where('student_priority.student_id', $registration->student_id)
            ->where('student_priority.status', 'verified')
            ->get([
                'priority_criteria.tier as tier',
                'priority_criteria.priority_score as priority_score',
            ]);

        if ($rows->isEmpty()) {
            $tier = self::NO_PRIORITY_TIER;
            $score = 0;
        } else {
            $tier = (int) $rows->min('tier');
            $score = (int) $rows->sum('priority_score');
        }

        $registration->top_priority_tier = $tier;
        $registration->total_priority_score = $score;
        $registration->save();

        return [
            'top_priority_tier' => $tier,
            'total_priority_score' => $score,
        ];
    }

    /**
     * Recalculate cached ranking values for every registration in a period.
     */
    public function recalculatePeriod(int $periodId): void
    {
        Registration::where('registration_period_id', $periodId)
            ->get()
            ->each(fn (Registration $registration) => $this->calculateForRegistration($registration));
    }

    /**
     * Rank a period's registrations by the tiered policy and split them into
     * the approved group (cut at the number of available beds) and the
     * waitlist (the remainder).
     *
     * Equivalent SQL ordering:
     *   ORDER BY top_priority_tier ASC, total_priority_score DESC, created_at ASC
     *
     * @return array{ranked: Collection<int, Registration>, approved: Collection<int, Registration>, waitlist: Collection<int, Registration>}
     */
    public function rankPeriod(int $periodId, int $availableBeds, bool $recalculate = true): array
    {
        if ($recalculate) {
            $this->recalculatePeriod($periodId);
        }

        $ranked = Registration::where('registration_period_id', $periodId)
            ->orderBy('top_priority_tier', 'asc')
            ->orderByDesc('total_priority_score')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc') // deterministic final tie-break only
            ->get();

        $availableBeds = max(0, $availableBeds);

        return [
            'ranked' => $ranked,
            'approved' => $ranked->take($availableBeds)->values(),
            'waitlist' => $ranked->slice($availableBeds)->values(),
        ];
    }
}
