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
     * Recalculate submitted reservations that have not received a proposal yet.
     */
    public function recalculateReservationPeriod(int $periodId): void
    {
        DormReservation::where('registration_period_id', $periodId)
            ->where('status', 'submitted')
            ->whereNull('auto_decision')
            ->whereDoesntHave('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->get()
            ->each(fn (DormReservation $r) => $this->calculateForReservation($r));
    }

    /**
     * Rank submitted reservations without proposals for a period and split into
     * approved (top N) and waitlist (remainder).
     *
     * Giường tách riêng theo giới tính (xem rankPeriod()) — xếp hạng ở đây cũng phải tách
     * riêng nam/nữ, dùng đúng suất trống riêng từng giới. Candidate CHƯA có giới tính
     * (`admission_candidates.gender` nullable, khác Student.gender bắt buộc) không tự suy
     * đoán được thuộc nhóm nào — người gọi (controller) phải tự kiểm tra và chặn xếp hạng
     * cả đợt nếu còn candidate thiếu giới tính, GIỐNG CÁCH đang chặn khi còn minh chứng ưu
     * tiên pending, trước khi gọi hàm này.
     *
     * @param array{male: int, female: int} $availableBedsByGender
     * @return array{
     *   ranked: Collection<int, DormReservation>,
     *   approved: Collection<int, DormReservation>,
     *   waitlist: Collection<int, DormReservation>,
     *   byGender: array<string, array{ranked: Collection<int, DormReservation>, approved: Collection<int, DormReservation>, waitlist: Collection<int, DormReservation>}>
     * }
     */
    public function rankReservationPeriod(int $periodId, array $availableBedsByGender, bool $recalculate = true): array
    {
        if ($recalculate) {
            $this->recalculateReservationPeriod($periodId);
        }

        // Loại hoàn toàn hồ sơ có minh chứng ưu tiên bị từ chối khỏi tập eligible — không
        // chỉ cho điểm 0/tier thấp, mà không được xếp hạng/đề xuất duyệt/waitlist ở đây
        // nữa (đã chuyển rejected ngay khi admin từ chối minh chứng, filter này chỉ để
        // phòng vệ thêm với dữ liệu cũ/race, không đổi thuật toán tính điểm/thứ tự).
        $ranked = DormReservation::where('registration_period_id', $periodId)
            ->where('status', 'submitted')
            ->whereNull('auto_decision')
            ->whereDoesntHave('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->with('candidate:id,gender')
            ->orderBy('top_priority_tier', 'asc')
            ->orderByDesc('total_priority_score')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $approved = collect();
        $waitlist = collect();
        $byGender = [];

        foreach (['male', 'female'] as $gender) {
            $bucket = $ranked->filter(
                fn (DormReservation $r) => strtolower($r->candidate->gender ?? '') === $gender
            )->values();
            $beds = max(0, $availableBedsByGender[$gender] ?? 0);
            $genderApproved = $bucket->take($beds)->values();
            $genderWaitlist = $bucket->slice($beds)->values();

            $byGender[$gender] = [
                'ranked' => $bucket,
                'approved' => $genderApproved,
                'waitlist' => $genderWaitlist,
            ];
            $approved = $approved->concat($genderApproved);
            $waitlist = $waitlist->concat($genderWaitlist);
        }

        return [
            'ranked'   => $ranked,
            'approved' => $approved->values(),
            'waitlist' => $waitlist->values(),
            'byGender' => $byGender,
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
     * QUAN TRỌNG: giường KTX tách riêng theo giới tính ở cấp Floor (không phải 1 hồ chung) —
     * xếp hạng/cắt suất PHẢI làm riêng cho từng giới, nếu gộp chung 1 số suất sẽ duyệt lố 1
     * giới trong khi giới kia còn dư (báo cáo 27/07: 3 nữ được duyệt dù chỉ có 2 giường nữ,
     * vì trước đây cắt theo 1 tổng số suất chung không phân biệt giới). `$availableBedsByGender`
     * phải có đủ 2 khóa 'male'/'female' (số suất trống riêng của giới đó, lấy từ
     * DormCapacityService::summarizeForRegistrationPeriod(..., gender: 'male'/'female')).
     *
     * @param array{male: int, female: int} $availableBedsByGender
     * @return array{
     *   ranked: Collection<int, Registration>,
     *   approved: Collection<int, Registration>,
     *   waitlist: Collection<int, Registration>,
     *   byGender: array<string, array{ranked: Collection<int, Registration>, approved: Collection<int, Registration>, waitlist: Collection<int, Registration>}>
     * }
     */
    public function rankPeriod(int $periodId, array $availableBedsByGender, bool $recalculate = true): array
    {
        if ($recalculate) {
            $this->recalculatePeriod($periodId);
        }

        // Gộp CHUNG đăng ký thường và Registration nguồn giữ chỗ tân sinh viên
        // (source_dorm_reservation_id NOT NULL) vào 1 bảng xếp hạng duy nhất — trước đây tách
        // riêng khiến hồ sơ giữ chỗ (kể cả tier 99/điểm 0) luôn giữ auto_decision='approve'
        // mặc định, "vượt mặt" hồ sơ đăng ký thường có điểm ưu tiên cao hơn nhiều (báo cáo
        // 24/07: 2 hồ sơ giữ chỗ điểm 0 được duyệt trong khi hồ sơ điểm 100 bị từ chối, chỉ vì
        // suất đã bị nhóm giữ chỗ trừ trước theo kênh riêng). Giờ đây một hồ sơ giữ chỗ có thể
        // bị đổi từ approve -> reject nếu thua điểm — xem RegistrationController::
        // confirmSingle()/confirmBatch() để biết reservation nguồn được chuyển về 'waitlisted'
        // + báo cho sinh viên khi việc này xảy ra lúc admin xác nhận.
        //
        // Loại hoàn toàn hồ sơ có minh chứng ưu tiên bị từ chối khỏi tập eligible — xem ghi
        // chú tương tự ở rankReservationPeriod().
        //
        // Loại 'cancelled' khỏi bảng xếp hạng — đây là đơn sinh viên đã TỰ RÚT, không còn
        // cạnh tranh suất nào nữa. Trước đây không lọc status nên 1 đơn đã cancelled vẫn
        // chiếm 1 vị trí trong bucket theo giới, đẩy người xếp hạng thấp hơn ra khỏi chỉ
        // tiêu dù suất của người đã rút thực chất đang bỏ trống (báo cáo 28/07: Hoàng Khánh
        // Lan tự hủy nhưng vẫn tính vào "3/3" khiến Trần Thị Bích bị từ chối oan dù còn suất).
        $ranked = Registration::where('registration_period_id', $periodId)
            ->where('status', '!=', 'cancelled')
            ->whereDoesntHave('studentPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->with(['sourceDormReservation:id,submitted_at,created_at', 'student:id,gender'])
            ->orderBy('top_priority_tier', 'asc')
            ->orderByDesc('total_priority_score')
            ->get()
            ->sort(function (Registration $a, Registration $b) {
                if ($a->top_priority_tier !== $b->top_priority_tier) {
                    return $a->top_priority_tier <=> $b->top_priority_tier;
                }
                if ($a->total_priority_score !== $b->total_priority_score) {
                    return $b->total_priority_score <=> $a->total_priority_score;
                }
                $timeDiff = $this->originalSubmittedAt($a)->timestamp <=> $this->originalSubmittedAt($b)->timestamp;

                return $timeDiff !== 0 ? $timeDiff : ($a->id <=> $b->id); // id: tie-break cuối, ổn định
            })
            ->values();

        $approved = collect();
        $waitlist = collect();
        $byGender = [];

        foreach (['male', 'female'] as $gender) {
            $bucket = $ranked->filter(
                fn (Registration $r) => strtolower($r->student->gender ?? '') === $gender
            )->values();
            $beds = max(0, $availableBedsByGender[$gender] ?? 0);
            $genderApproved = $bucket->take($beds)->values();
            $genderWaitlist = $bucket->slice($beds)->values();

            $byGender[$gender] = [
                'ranked' => $bucket,
                'approved' => $genderApproved,
                'waitlist' => $genderWaitlist,
            ];
            $approved = $approved->concat($genderApproved);
            $waitlist = $waitlist->concat($genderWaitlist);
        }

        return [
            'ranked' => $ranked,
            'approved' => $approved->values(),
            'waitlist' => $waitlist->values(),
            'byGender' => $byGender,
        ];
    }

    /**
     * Xếp hạng suất DƯ (còn lại sau khi rankPeriod() đã chốt xong bên Đơn đăng ký) cho hồ sơ
     * giữ chỗ đang WAITLISTED mà candidate chưa nhập học (chưa có Registration thật) — theo
     * đúng thứ tự thống nhất ngày 24/07: nhóm chưa nhập học KHÔNG được cạnh tranh trực tiếp
     * với Đơn đăng ký (chưa có "đơn" nào để mà cạnh tranh), chỉ được xét đôn lên suất DƯ sau
     * cùng, ưu tiên luôn dành cho người đã có Registration thật trước.
     *
     * Suất dư cũng tách riêng theo giới (xem rankPeriod()) — nhận `$leftoverBedsByGender`
     * thay vì 1 số chung, đôn riêng từng giới rồi gộp kết quả lại thành 1 danh sách như cũ.
     *
     * @param array{male: int, female: int} $leftoverBedsByGender
     * @return Collection<int, DormReservation>
     */
    public function rankLeftoverForWaitlistedReservations(int $periodId, array $leftoverBedsByGender): Collection
    {
        if (max($leftoverBedsByGender['male'] ?? 0, $leftoverBedsByGender['female'] ?? 0) <= 0) {
            return collect();
        }

        $waitlisted = DormReservation::where('registration_period_id', $periodId)
            ->where('status', 'waitlisted')
            ->whereDoesntHave('convertedIntoRegistration')
            ->whereDoesntHave('reservationPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->with('candidate:id,gender')
            ->get()
            ->each(fn (DormReservation $r) => $this->calculateForReservation($r));

        $sorted = $waitlisted->sort(function (DormReservation $a, DormReservation $b) {
            if ($a->top_priority_tier !== $b->top_priority_tier) {
                return $a->top_priority_tier <=> $b->top_priority_tier;
            }
            if ($a->total_priority_score !== $b->total_priority_score) {
                return $b->total_priority_score <=> $a->total_priority_score;
            }
            $timeA = $a->submitted_at ?? $a->created_at;
            $timeB = $b->submitted_at ?? $b->created_at;
            $timeDiff = $timeA->timestamp <=> $timeB->timestamp;

            return $timeDiff !== 0 ? $timeDiff : ($a->id <=> $b->id);
        })->values();

        $promoted = collect();
        foreach (['male', 'female'] as $gender) {
            $leftoverBeds = max(0, $leftoverBedsByGender[$gender] ?? 0);
            if ($leftoverBeds === 0) {
                continue;
            }
            $bucket = $sorted->filter(
                fn (DormReservation $r) => strtolower($r->candidate->gender ?? '') === $gender
            )->values();
            $promoted = $promoted->concat($bucket->take($leftoverBeds));
        }

        return $promoted->values();
    }

    /**
     * Xếp hạng lại danh sách Registration bị TỪ CHỐI VÌ HẾT CHỈ TIÊU (không phải vì minh
     * chứng ưu tiên không hợp lệ hay lý do khác) trong đợt, để đôn lên `approve` khi có suất
     * giữ chỗ được giải phóng (ví dụ hồ sơ giữ chỗ approved-chưa-nhập-học bị hết hạn/expire
     * — xem AutoCloseAdmissionPeriodsCommand). Lấy đúng số lượng bằng số suất vừa giải phóng,
     * theo đúng thứ tự tier/điểm/mốc nộp gốc — người xếp hạng cao nhất trong nhóm bị từ chối
     * được đôn lên trước, đúng tinh thần "suất giải phóng phải về tay người xứng đáng nhất
     * còn lại, không lãng phí" (báo cáo 25/07).
     *
     * Chỉ nhận diện đúng nhóm bị từ chối vì hết chỉ tiêu qua `rejection_reason` do
     * RegistrationPeriodController::process() tự sinh ("Không đủ chỉ tiêu — xếp hạng thứ
     * X/Y...") — không đôn nhầm người bị từ chối vì lý do khác (minh chứng không hợp lệ,
     * admin từ chối tay với lý do riêng...).
     *
     * Suất giải phóng thuộc về đúng 1 giới (chính giới của candidate vừa hết hạn/expire) —
     * nhận `$freedSlotsByGender` thay vì 1 số chung để không đôn nhầm người khác giới lên
     * thế chỗ (xem rankPeriod() về lý do phải tách theo giới).
     *
     * @param array{male: int, female: int} $freedSlotsByGender
     * @return Collection<int, Registration>
     */
    public function rankRejectedRegistrationsForFreedSlots(int $periodId, array $freedSlotsByGender): Collection
    {
        if (max($freedSlotsByGender['male'] ?? 0, $freedSlotsByGender['female'] ?? 0) <= 0) {
            return collect();
        }

        // Dò theo auto_decision_reason (giữ nguyên bản gốc "Không đủ chỉ tiêu (nam)...") —
        // KHÔNG dò rejection_reason vì cột đó đã bị confirmBatch() ghi đè bằng bản dịch thân
        // thiện cho sinh viên đọc (RegistrationController::studentFacingRejectionReason()),
        // không còn giữ đúng mẫu để nhận diện "bị từ chối do hết chỉ tiêu" nữa (báo cáo
        // 30/07: Anh, Bảo không được đôn dù suất đã giải phóng đúng).
        $rejected = Registration::where('registration_period_id', $periodId)
            ->where('status', 'rejected')
            ->where('auto_decision', 'reject')
            ->where('auto_decision_reason', 'like', 'Không đủ chỉ tiêu%')
            ->whereDoesntHave('studentPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->with(['sourceDormReservation:id,submitted_at,created_at', 'student:id,gender'])
            ->get();

        $sorted = $rejected->sort(function (Registration $a, Registration $b) {
            if ($a->top_priority_tier !== $b->top_priority_tier) {
                return $a->top_priority_tier <=> $b->top_priority_tier;
            }
            if ($a->total_priority_score !== $b->total_priority_score) {
                return $b->total_priority_score <=> $a->total_priority_score;
            }
            $timeDiff = $this->originalSubmittedAt($a)->timestamp <=> $this->originalSubmittedAt($b)->timestamp;

            return $timeDiff !== 0 ? $timeDiff : ($a->id <=> $b->id);
        })->values();

        $promoted = collect();
        foreach (['male', 'female'] as $gender) {
            $freedSlots = max(0, $freedSlotsByGender[$gender] ?? 0);
            if ($freedSlots === 0) {
                continue;
            }
            $bucket = $sorted->filter(
                fn (Registration $r) => strtolower($r->student->gender ?? '') === $gender
            )->values();
            $promoted = $promoted->concat($bucket->take($freedSlots));
        }

        return $promoted->values();
    }

    /** Mốc "nộp hồ sơ" thật để tie-break công bằng giữa 2 nguồn: Registration nguồn giữ chỗ
     *  lấy submitted_at GỐC của DormReservation (lúc thí sinh nộp giữ chỗ), KHÔNG lấy
     *  Registration.created_at (lúc DormReservationConversionService::convert() chạy — luôn
     *  trễ hơn nhiều vì chỉ xảy ra sau khi thí sinh đã nhập học). */
    private function originalSubmittedAt(Registration $registration): \Carbon\Carbon
    {
        if ($registration->source_dorm_reservation_id && $registration->sourceDormReservation) {
            return $registration->sourceDormReservation->submitted_at
                ?? $registration->sourceDormReservation->created_at
                ?? $registration->created_at;
        }

        return $registration->created_at;
    }
}
