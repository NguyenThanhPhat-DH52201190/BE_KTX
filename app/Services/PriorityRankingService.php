<?php

namespace App\Services;

use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\ReservationPriority;
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
     * the student's VERIFIED priority criteria for this specific registration,
     * then persist onto the registration. Returns the computed values.
     *
     * Only criteria linked to this exact registration_id are counted.
     * Unverified (pending) or admin-rejected criteria are excluded.
     * If a student has no verified criteria, tier = 99 and score = 0.
     *
     * @return array{top_priority_tier: int, total_priority_score: int}
     */
    public function calculateForRegistration(Registration $registration): array
    {
        $rows = StudentPriority::query()
            ->join('priority_criteria', 'student_priority.priority_criteria_id', '=', 'priority_criteria.id')
            ->where('student_priority.registration_id', $registration->id)
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
        // Registration nguồn giữ chỗ (tân sinh viên, source_dorm_reservation_id NOT NULL) đã
        // được xếp hạng/duyệt ở cấp DormReservation từ trước, suất đã cam kết — không tính
        // lại điểm/tier ở đây, tránh ghi đè top_priority_tier/total_priority_score đã copy
        // nguyên vẹn từ reservation lúc convert().
        Registration::where('registration_period_id', $periodId)
            ->whereNull('source_dorm_reservation_id')
            ->whereDoesntHave('studentPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->get()
            ->each(fn (Registration $registration) => $this->calculateForRegistration($registration));
    }

    // =========================================================
    // Dorm Reservation priority (tân sinh viên)
    // =========================================================

    /**
     * Compute top_priority_tier and total_priority_score from VERIFIED
     * reservation_priorities for this dorm_reservation, then persist.
     *
     * @return array{top_priority_tier: int, total_priority_score: int}
     */
    public function calculateForReservation(DormReservation $reservation): array
    {
        $rows = ReservationPriority::query()
            ->join('priority_criteria', 'reservation_priorities.priority_criteria_id', '=', 'priority_criteria.id')
            ->where('reservation_priorities.dorm_reservation_id', $reservation->id)
            ->where('reservation_priorities.status', 'verified')
            ->get([
                'priority_criteria.tier as tier',
                'priority_criteria.priority_score as priority_score',
            ]);

        if ($rows->isEmpty()) {
            $tier  = self::NO_PRIORITY_TIER;
            $score = 0;
        } else {
            $tier  = (int) $rows->min('tier');
            $score = (int) $rows->sum('priority_score');
        }

        $reservation->top_priority_tier    = $tier;
        $reservation->total_priority_score = $score;
        $reservation->save();

        return [
            'top_priority_tier'    => $tier,
            'total_priority_score' => $score,
        ];
    }

    /**
     * Recalculate all submitted/waitlisted reservations in a period.
     */
    public function recalculateReservationPeriod(int $periodId): void
    {
        DormReservation::where('registration_period_id', $periodId)
            ->whereIn('status', ['submitted', 'waitlisted'])
            ->whereDoesntHave('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->get()
            ->each(fn (DormReservation $r) => $this->calculateForReservation($r));
    }

    /**
     * Rank submitted+waitlisted reservations for a period and split into
     * approved (top N) and waitlist (remainder).
     *
     * @return array{ranked: Collection<int, DormReservation>, approved: Collection<int, DormReservation>, waitlist: Collection<int, DormReservation>}
     */
    public function rankReservationPeriod(int $periodId, int $availableBeds, bool $recalculate = true): array
    {
        if ($recalculate) {
            $this->recalculateReservationPeriod($periodId);
        }

        // Loại hoàn toàn hồ sơ có minh chứng ưu tiên bị từ chối khỏi tập eligible — không
        // chỉ cho điểm 0/tier thấp, mà không được xếp hạng/đề xuất duyệt/waitlist ở đây
        // nữa (đã chuyển rejected ngay khi admin từ chối minh chứng, filter này chỉ để
        // phòng vệ thêm với dữ liệu cũ/race, không đổi thuật toán tính điểm/thứ tự).
        $ranked = DormReservation::where('registration_period_id', $periodId)
            ->whereIn('status', ['submitted', 'waitlisted'])
            ->whereDoesntHave('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->orderBy('top_priority_tier', 'asc')
            ->orderByDesc('total_priority_score')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $availableBeds = max(0, $availableBeds);

        return [
            'ranked'   => $ranked,
            'approved' => $ranked->take($availableBeds)->values(),
            'waitlist' => $ranked->slice($availableBeds)->values(),
        ];
    }

    // =========================================================
    // Registration priority (sinh viên cũ)
    // =========================================================

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

        // Loại hoàn toàn hồ sơ có minh chứng ưu tiên bị từ chối khỏi tập eligible — xem
        // ghi chú tương tự ở rankReservationPeriod(). Loại luôn Registration nguồn giữ chỗ
        // (source_dorm_reservation_id NOT NULL) — suất của chúng đã được cam kết từ
        // DormReservation approved trước đó (không nằm trong $availableBeds truyền vào), xếp
        // hạng lại có thể đổi auto_decision approve→reject và làm mất suất đã giữ.
        $ranked = Registration::where('registration_period_id', $periodId)
            ->whereNull('source_dorm_reservation_id')
            ->whereDoesntHave('studentPriorities', fn ($q) => $q->where('status', 'rejected'))
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