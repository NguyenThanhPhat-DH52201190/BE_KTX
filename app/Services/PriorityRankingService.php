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
        //
        // Loại thêm 'rejected' — admin "Từ chối" tay qua patchAutoDecision() giờ chốt thật
        // ngay lập tức (status='rejected'), không còn là đề xuất chờ nữa. Đơn đã bị từ chối
        // thật không được cạnh tranh lại ở các lần xếp hạng sau (xem báo cáo sửa lỗi "Xếp
        // hạng lại ghi đè mất quyết định tay của admin").
        //
        // Loại thêm 'approved' — đơn đã được XÁC NHẬN CHÍNH THỨC (confirmSingle/confirmBatch)
        // suất của họ đã bị trừ SẴN vào $availableBedsByGender (tính qua
        // DormCapacityService::summarizeForRegistrationPeriod()['available_approval_slots'],
        // vốn = tổng giường - số đơn approved). Nếu KHÔNG loại họ khỏi bucket này, họ vẫn nằm
        // trong danh sách cạnh tranh cho đúng con số suất ĐÃ TRỪ HỌ RỒI — tức 1 suất bị trừ 2
        // lần: 1 lần lúc tính available_approval_slots, 1 lần nữa lúc họ "thắng" take($beds).
        // Hệ quả: hễ đã có 1 người approved, top_priority_tier của họ luôn cao nhất nên vĩnh
        // viễn không ai khác được duyệt thêm dù giường vẫn còn trống thật (báo cáo 01/08: Võ
        // Quốc Bảo đã duyệt trước, sau đó "Xếp hạng lại" chạy lại — dù còn 1 giường nam trống —
        // vẫn không duyệt thêm được ai vì Bảo tự chiếm lại đúng suất đó trong phép tính).
        $ranked = Registration::where('registration_period_id', $periodId)
            ->whereNotIn('status', ['cancelled', 'rejected', 'approved'])
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
            [$genderApproved, $genderWaitlist] = $this->splitBucketWithManualPins($bucket, $beds);

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
     * rankPeriod() + ghi luôn kết quả xuống auto_decision/auto_decision_reason cho từng
     * Registration — dùng chung cho cả "Xếp hạng lại" (RegistrationPeriodController::process(),
     * cả 2 giới) lẫn các nơi cần tự cập nhật NGAY sau khi 1 đơn bị loại khỏi cạnh tranh
     * (Từ chối tay, sinh viên tự hủy giữ chỗ...) mà chỉ cần cập nhật đúng 1 giới bị ảnh hưởng,
     * không cần chờ admin bấm "Xếp hạng lại" thủ công.
     *
     * @param array{male: int, female: int} $availableBedsByGender
     * @param string|null $onlyGender Chỉ ghi kết quả cho 1 giới ('male'/'female') — dùng khi
     *   chỉ cần cập nhật tức thời 1 giới vừa có suất trống, không đụng tới giới còn lại.
     * @return array{
     *   ranked: Collection<int, Registration>,
     *   approved: Collection<int, Registration>,
     *   waitlist: Collection<int, Registration>,
     *   byGender: array<string, array{ranked: Collection<int, Registration>, approved: Collection<int, Registration>, waitlist: Collection<int, Registration>}>
     * }
     */
    public function applyRankingDecisions(int $periodId, array $availableBedsByGender, ?string $onlyGender = null): array
    {
        $rankResult = $this->rankPeriod($periodId, $availableBedsByGender, recalculate: true);

        $genderLabel = ['male' => 'nam', 'female' => 'nữ'];
        $genders = $onlyGender ? [$onlyGender] : ['male', 'female'];

        foreach ($genders as $gender) {
            if (!isset($rankResult['byGender'][$gender])) {
                continue;
            }

            $bucket = $rankResult['byGender'][$gender];

            foreach ($bucket['approved'] as $reg) {
                $reg->auto_decision = 'approve';
                $reg->auto_decision_reason = null;
                $reg->save();
            }

            $approvedCount = $bucket['approved']->count();
            $total = $approvedCount + $bucket['waitlist']->count();
            foreach ($bucket['waitlist'] as $index => $reg) {
                $rank = $approvedCount + $index + 1;
                $reg->auto_decision = 'reject';
                $reg->auto_decision_reason = "Không đủ chỉ tiêu ({$genderLabel[$gender]}) — xếp hạng thứ {$rank}/{$total}, chỉ tiêu {$availableBedsByGender[$gender]} suất.";
                $reg->save();
            }
        }

        return $rankResult;
    }

    /**
     * Chia 1 bucket (đã xếp hạng theo tier/điểm/thời gian, cùng 1 giới) thành duyệt/waitlist,
     * có tính đến "ghim tay" (decision_source='manual', manual_decision='approve').
     *
     * Quy tắc (thống nhất 01/08): ghim tay là hành động MỘT LẦN, tại đúng thời điểm admin bấm
     * xác nhận (xem resolveManualApprove()) — không phải thứ được "tính lại từ đầu" mỗi lần xếp
     * hạng. Sau khi ghim thắng 1 lần (auto_decision đã là 'approve'), người đó được COI NHƯ ĐÃ
     * KHÓA CỨNG suất của mình — chỉ đơn giản bị trừ khỏi số suất còn lại, không tiếp tục tranh
     * chấp gì nữa ở các lần xếp hạng SAU (kể cả khi có người khác bị từ chối/hủy phát sinh suất
     * mới — suất mới đó phải chia theo tiêu chí ưu tiên cho phần TỰ NHIÊN còn lại, không tự
     * động "nhảy vào" cho 1 ghim cũ nào khác). Đơn từng được ghim nhưng ĐÃ THUA (auto_decision
     * hiện không phải 'approve') không còn được ưu tiên gì — cạnh tranh lại hoàn toàn bình
     * thường theo tier/điểm như một đơn tự nhiên, y hệt như chưa từng được ghim.
     *
     * @param Collection<int, Registration> $bucket
     * @return array{0: Collection<int, Registration>, 1: Collection<int, Registration>}
     */
    private function splitBucketWithManualPins(Collection $bucket, int $beds): array
    {
        $isLockedWinner = fn (Registration $r) => $r->decision_source === 'manual'
            && $r->manual_decision === 'approve'
            && $r->auto_decision === 'approve';

        $lockedPinned = $bucket->filter($isLockedWinner)->values();
        $rest = $bucket->reject($isLockedWinner)->values();

        $remainingBeds = max(0, $beds - $lockedPinned->count());
        $naturalApproved = $rest->take($remainingBeds)->values();

        $approved = $lockedPinned->concat($naturalApproved)->values();
        $approvedIds = $approved->pluck('id')->all();
        // Giữ nguyên thứ tự tier/điểm gốc của $bucket cho waitlist để hiển thị thứ hạng nhất quán.
        $genderWaitlist = $bucket->reject(fn (Registration $r) => in_array($r->id, $approvedIds, true))->values();

        return [$approved, $genderWaitlist];
    }

    /**
     * Xử lý MỘT LẦN hành động "ghim tay Duyệt" cho $targetId — tìm người tier/điểm thấp nhất
     * đang trong nhóm Duyệt hiện tại (đã tính cả các ghim đã khóa cứng từ trước) để nhường chỗ,
     * nếu suất đã đầy. Không ghi DB — chỉ tính toán, trả về ai sẽ bị đẩy (null nếu không cần
     * đẩy ai, hoặc nếu hết giường vật lý nên target không thể thắng).
     *
     * @param Collection<int, Registration> $bucket
     * @return array{targetWins: bool, bumped: ?Registration}
     */
    private function resolveManualApprove(Collection $bucket, int $beds, int $targetId): array
    {
        [$approved] = $this->splitBucketWithManualPins($bucket, $beds);

        if ($approved->contains(fn (Registration $r) => $r->id === $targetId)) {
            return ['targetWins' => true, 'bumped' => null];
        }

        if ($approved->count() < $beds) {
            return ['targetWins' => true, 'bumped' => null];
        }

        if ($approved->isEmpty()) {
            // beds = 0 — không có suất vật lý nào để thắng, kể cả ghim tay cũng chịu.
            return ['targetWins' => false, 'bumped' => null];
        }

        $weakest = $approved->sort(function (Registration $a, Registration $b) {
            if ($a->top_priority_tier !== $b->top_priority_tier) {
                return $b->top_priority_tier <=> $a->top_priority_tier; // tier lớn hơn (tệ hơn) lên đầu
            }
            if ($a->total_priority_score !== $b->total_priority_score) {
                return $a->total_priority_score <=> $b->total_priority_score; // điểm thấp hơn lên đầu
            }

            // Trùng cả tier lẫn điểm — ai NỘP SAU thì coi là "tệ hơn" (lên đầu, bị đẩy trước),
            // khớp đúng quy tắc tie-break chuẩn toàn hệ thống (nộp trước luôn được ưu tiên hơn).
            $timeDiff = $this->originalSubmittedAt($b)->timestamp <=> $this->originalSubmittedAt($a)->timestamp;

            return $timeDiff !== 0 ? $timeDiff : ($b->id <=> $a->id);
        })->first();

        return ['targetWins' => true, 'bumped' => $weakest];
    }

    /**
     * Xây bucket đã xếp hạng (tier/điểm/thời gian) cho ĐÚNG 1 giới của 1 đợt — dùng chung cho
     * previewManualApprove()/applyManualApprove(), tách riêng khỏi rankPeriod() vì 2 hàm này
     * chỉ cần xử lý 1 giới, không cần tính cả 2 giới như rankPeriod().
     *
     * @return Collection<int, Registration>
     */
    private function buildGenderBucket(int $periodId, string $gender): Collection
    {
        return Registration::where('registration_period_id', $periodId)
            ->whereNotIn('status', ['cancelled', 'rejected', 'approved'])
            ->whereDoesntHave('studentPriorities', fn ($q) => $q->where('status', 'rejected'))
            ->with(['sourceDormReservation:id,submitted_at,created_at', 'student:id,gender,full_name,student_code'])
            ->orderBy('top_priority_tier', 'asc')
            ->orderByDesc('total_priority_score')
            ->get()
            ->filter(fn (Registration $r) => strtolower($r->student->gender ?? '') === $gender)
            ->sort(function (Registration $a, Registration $b) {
                if ($a->top_priority_tier !== $b->top_priority_tier) {
                    return $a->top_priority_tier <=> $b->top_priority_tier;
                }
                if ($a->total_priority_score !== $b->total_priority_score) {
                    return $b->total_priority_score <=> $a->total_priority_score;
                }
                $timeDiff = $this->originalSubmittedAt($a)->timestamp <=> $this->originalSubmittedAt($b)->timestamp;

                return $timeDiff !== 0 ? $timeDiff : ($a->id <=> $b->id);
            })
            ->values();
    }

    /**
     * Xem trước hệ quả nếu ghim tay Duyệt cho 1 đơn — dùng để cảnh báo admin trước khi xác
     * nhận (hiện tên người sẽ bị đẩy xuống waitlist, nếu có). Không ghi gì xuống DB.
     *
     * @return array{bumped: ?Registration}|null null nếu đơn không hợp lệ/không xác định được giới tính.
     */
    public function previewManualApprove(int $registrationId, int $periodId, array $availableBedsByGender): ?array
    {
        $target = Registration::with('student:id,gender')->find($registrationId);
        $gender = strtolower($target?->student?->gender ?? '');
        if (!$target || !in_array($gender, ['male', 'female'], true)) {
            return null;
        }

        $bucket = $this->buildGenderBucket($periodId, $gender);
        $beds = max(0, $availableBedsByGender[$gender] ?? 0);

        $result = $this->resolveManualApprove($bucket, $beds, $registrationId);

        return ['bumped' => $result['bumped']];
    }

    /**
     * Thực thi THẬT hành động ghim tay Duyệt cho $target — ghi DB. Nếu có người bị đẩy, người
     * đó được chuyển hẳn về trạng thái tự nhiên (gỡ cờ ghim cũ nếu có — họ đã "dùng hết" lượt
     * ghim của mình, không còn được ưu tiên gì cho các suất phát sinh sau này) và
     * auto_decision='reject'. $target được khóa cứng Duyệt (decision_source='manual').
     *
     * @return array{winner: Registration, bumped: ?Registration}
     */
    public function applyManualApprove(Registration $target, int $beds, ?string $reason): array
    {
        $gender = strtolower($target->student?->gender ?? '');
        $bucket = $this->buildGenderBucket($target->registration_period_id, $gender);
        $result = $this->resolveManualApprove($bucket, $beds, $target->id);

        if ($result['bumped']) {
            $bumped = $result['bumped'];
            $bumped->decision_source = null;
            $bumped->manual_decision = null;
            $bumped->manual_decision_reason = null;
            $bumped->auto_decision = 'reject';
            $bumped->auto_decision_reason = 'Không đủ chỉ tiêu — nhường suất cho 1 trường hợp được duyệt tay đặc cách.';
            $bumped->save();
        }

        $target->decision_source = 'manual';
        $target->manual_decision = 'approve';
        $target->manual_decision_reason = $reason;
        $target->auto_decision = 'approve';
        $target->auto_decision_reason = null;
        $target->save();

        return ['winner' => $target, 'bumped' => $result['bumped']];
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
    /** Public vì AutoRoomAssignmentService cũng cần đúng mốc nộp gốc này để xếp thứ tự gán
     *  phòng nhất quán với thứ tự xếp hạng duyệt (không dùng created_at thô — với tân sinh
     *  viên convert từ giữ chỗ, created_at là thời điểm convert/nhập học, TRỄ hơn thời điểm
     *  nộp hồ sơ giữ chỗ gốc). */
    public function originalSubmittedAt(Registration $registration): \Carbon\Carbon
    {
        if ($registration->source_dorm_reservation_id && $registration->sourceDormReservation) {
            return $registration->sourceDormReservation->submitted_at
                ?? $registration->sourceDormReservation->created_at
                ?? $registration->created_at;
        }

        return $registration->created_at;
    }
}
