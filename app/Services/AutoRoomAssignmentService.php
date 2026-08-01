<?php

namespace App\Services;

use App\Models\Occupancy;
use App\Models\Registration;
use App\Models\Room;
use Carbon\Carbon;

class AutoRoomAssignmentService
{
    /**
     * Run auto room assignment for all approved registrations with no confirmed room.
     * Safe to re-run: if a PROPOSED occupancy already exists it is updated in-place,
     * never duplicated. If no room is found for a previously-proposed student,
     * the stale proposal is deleted so the student reverts to "unassigned".
     *
     * Sort order:
     *   1. top_priority_tier ASC  (lower tier = higher priority)
     *   2. total_priority_score DESC
     *   3. province_code ASC  (cluster same-province students within a tier)
     *   4. faculty ASC, course_year ASC
     *   5. created_at ASC  (earlier submission wins tie)
     *
     * Room selection:
     *   a. Gender match: floor.gender = student.gender (or floor has no gender set)
     *   b. Province clustering bonus (+100 if room already holds same province)
     *   c. Floor overflow bonus (+50 for same floor used by this province group)
     *   d. Faculty+year cohesion bonus (+20 / +10)
     *   e. Packing bonus: prefer more-filled rooms to keep groups together
     *
     * @return array{assigned: int, no_room: int, details: array}
     */
    public function run(?int $registrationPeriodId = null): array
    {
        // 1. Eligible: approved with no confirmed, pending-payment, or active occupancy
        //    (includes registrations with existing PROPOSED — we will update them)
        $query = Registration::query()
            ->where('status', 'approved')
            ->with(['student', 'period', 'sourceDormReservation:id,submitted_at,created_at'])
            ->whereNotExists(function ($sub) {
                $sub->from('occupancy')
                    ->whereColumn('occupancy.registration_id', 'registrations.id')
                    ->whereIn('occupancy.status', ['ROOM_CONFIRMED', 'PENDING_PAYMENT', 'ACTIVE']);
            })
            ->orderBy('top_priority_tier', 'asc')
            ->orderByDesc('total_priority_score')
            ->orderBy('created_at', 'asc');

        if ($registrationPeriodId) {
            $query->where('registration_period_id', $registrationPeriodId);
        }

        $registrations = $query->get();

        // 2. Load existing PROPOSED occupancies in one batch to avoid N+1
        $existingProposed = Occupancy::where('status', 'PROPOSED')
            ->whereIn('registration_id', $registrations->pluck('id'))
            ->get()
            ->keyBy('registration_id');

        // 3. Secondary sort: cluster by province → faculty+year, while preserving priority
        $ranker = new PriorityRankingService();
        $sorted = $registrations->sort(function (Registration $a, Registration $b) use ($ranker) {
            if ($a->top_priority_tier !== $b->top_priority_tier) {
                return $a->top_priority_tier <=> $b->top_priority_tier;
            }
            if ($a->total_priority_score !== $b->total_priority_score) {
                return $b->total_priority_score <=> $a->total_priority_score;
            }
            $aProvince = $a->student?->province_code ?? '';
            $bProvince = $b->student?->province_code ?? '';
            if ($aProvince !== $bProvince) {
                return strcmp($aProvince, $bProvince);
            }
            $aGroup = ($a->student?->faculty ?? '') . '|' . ($a->student?->course_year ?? '');
            $bGroup = ($b->student?->faculty ?? '') . '|' . ($b->student?->course_year ?? '');
            if ($aGroup !== $bGroup) {
                return strcmp($aGroup, $bGroup);
            }
            // Dùng đúng mốc nộp GỐC (originalSubmittedAt) thay vì created_at thô — với tân sinh
            // viên convert từ giữ chỗ, created_at là thời điểm convert/nhập học (trễ hơn), sẽ
            // xếp sai thứ tự "ai nộp trước" nếu 2 người trùng hết các tiêu chí gom nhóm phía
            // trên (tỉnh, khoa, khóa). Dùng chung đúng logic PriorityRankingService đang dùng
            // để xếp hạng duyệt, tránh 2 nơi tính "ai nộp trước" ra 2 kết quả khác nhau.
            return $ranker->originalSubmittedAt($a)->timestamp <=> $ranker->originalSubmittedAt($b)->timestamp;
        })->values();

        // 4. Room availability map (PROPOSED occupancies do NOT count as occupied;
        //    confirmed rooms and pending-payment bed selections still reserve capacity)
        $roomMap    = $this->buildRoomMap();
        $roomTrack  = [];   // room_id => ['province'=>[], 'faculty'=>[], 'year'=>[]]
        $provinceFloor = []; // province_code => floor_id of first assigned room

        $assigned = 0;
        $noRoom   = 0;
        $details  = [];

        foreach ($sorted as $registration) {
            $student = $registration->student;
            if (!$student) {
                $noRoom++;
                continue;
            }

            $gender     = strtolower($student->gender ?? '');
            $province   = $student->province_code ?? '';
            $faculty    = $student->faculty ?? '';
            $courseYear = (string) ($student->course_year ?? '');
            $preferredFloor = ($province !== '') ? ($provinceFloor[$province] ?? null) : null;

            $chosenRoomId = $this->pickRoom(
                $roomMap, $roomTrack, $gender, $province, $faculty, $courseYear, $preferredFloor
            );

            $existing = $existingProposed->get($registration->id);

            if ($chosenRoomId === null) {
                // No suitable room — revert any stale proposal so student shows as unassigned
                if ($existing) {
                    $existing->delete();
                }
                $noRoom++;
                $details[] = [
                    'registration_id' => $registration->id,
                    'student_code'    => $student->student_code,
                    'name'            => $student->full_name,
                    'result'          => 'no_room',
                ];
                continue;
            }

            // Record floor for this province group (first hit only)
            if ($province !== '' && !isset($provinceFloor[$province])) {
                $provinceFloor[$province] = $roomMap[$chosenRoomId]['floor_id'];
            }

            // Upsert: update existing PROPOSED, or create a new one
            $period = $registration->period;
            if ($existing) {
                $existing->room_id        = $chosenRoomId;
                $existing->check_in_date  = $period?->stay_start_date?->toDateString();
                $existing->check_out_date = $period?->stay_end_date?->toDateString();
                $existing->save();
            } else {
                Occupancy::create([
                    'registration_id'    => $registration->id,
                    'student_id'         => $student->id,
                    'room_id'            => $chosenRoomId,
                    'bed_id'             => null,
                    'status'             => 'PROPOSED',
                    'bed_approval_status'=> null,
                    'check_in_date'      => $period?->stay_start_date?->toDateString(),
                    'check_out_date'     => $period?->stay_end_date?->toDateString(),
                ]);
            }

            // Update in-memory room tracking
            $roomMap[$chosenRoomId]['remaining']--;
            $roomTrack[$chosenRoomId]['province'][$province]  = ($roomTrack[$chosenRoomId]['province'][$province] ?? 0) + 1;
            $roomTrack[$chosenRoomId]['faculty'][$faculty]    = ($roomTrack[$chosenRoomId]['faculty'][$faculty] ?? 0) + 1;
            $roomTrack[$chosenRoomId]['year'][$courseYear]    = ($roomTrack[$chosenRoomId]['year'][$courseYear] ?? 0) + 1;

            $assigned++;
            $room = $roomMap[$chosenRoomId]['room'];
            $details[] = [
                'registration_id' => $registration->id,
                'student_code'    => $student->student_code,
                'name'            => $student->full_name,
                'result'          => 'assigned',
                'room_id'         => $chosenRoomId,
                'room_name'       => ($room->floor?->building_code ?? '') . $room->room_number,
            ];
        }

        return compact('assigned', 'noRoom', 'details') + ['no_room' => $noRoom];
    }

    /**
     * Confirm all PROPOSED occupancies → ROOM_CONFIRMED.
     *
     * @return array{confirmed: int, notifications: array}
     */
    public function confirmProposals(?int $registrationPeriodId = null): array
    {
        $query = Occupancy::where('status', 'PROPOSED')
            ->with(['registration.period', 'room.floor', 'student']);

        if ($registrationPeriodId) {
            $query->whereHas('registration', fn ($q) => $q->where('registration_period_id', $registrationPeriodId));
        }

        $proposed      = $query->get();
        $notifications = [];

        foreach ($proposed as $occupancy) {
            $occupancy->status = 'ROOM_CONFIRMED';
            $occupancy->save();

            $room            = $occupancy->room;
            $roomName        = ($room?->floor?->building_code ?? '') . ($room?->room_number ?? '');
            $deadline        = $this->bedSelectionDeadline($occupancy->registration);
            $message         = $this->notifier()->roomAssignmentContent($roomName, $deadline);

            if ($occupancy->student && $roomName !== '') {
                $this->notifier()->notifyRoomAssigned(
                    $occupancy->student,
                    $roomName,
                    $deadline,
                    $occupancy->registration_id,
                    queue: true,
                );
            }

            $notifications[] = [
                'student_code' => $occupancy->student?->student_code,
                'student_name' => $occupancy->student?->full_name,
                'room_id'      => $occupancy->room_id,
                'room_name'    => $roomName,
                'message'      => $message,
            ];
        }

        return ['confirmed' => count($notifications), 'notifications' => $notifications];
    }

    // ─── Private helpers ───────────────────────────────────────────────────

    private function buildRoomMap(): array
    {
        $rooms = Room::query()
            ->where('status', 'active')
            ->with(['floor', 'beds'])
            ->whereHas('floor', fn ($q) => $q->where('status', 'active'))
            ->get();

        // Confirmed rooms and pending-payment bed selections still reserve capacity.
        $occupiedPerRoom = Occupancy::whereIn('status', ['ROOM_CONFIRMED', 'PENDING_PAYMENT', 'ACTIVE'])
            ->selectRaw('room_id, COUNT(*) as cnt')
            ->groupBy('room_id')
            ->pluck('cnt', 'room_id');

        $map = [];
        foreach ($rooms as $room) {
            // Prefer active-bed count; fall back to rooms.capacity if beds aren't tracked
            $activeBeds = $room->beds->isNotEmpty()
                ? $room->beds->where('status', 'active')->count()
                : (int) ($room->capacity ?? 0);

            $occupied  = (int) ($occupiedPerRoom[$room->id] ?? 0);
            $remaining = max(0, $activeBeds - $occupied);
            if ($remaining === 0) continue;

            $map[$room->id] = [
                'room'        => $room,
                'gender'      => strtolower($room->floor?->gender ?? ''),
                'floor_id'    => $room->floor_id ?? 0,
                'active_beds' => $activeBeds,
                'remaining'   => $remaining,
            ];
        }
        return $map;
    }

    private function bedSelectionDeadline(?Registration $registration): ?Carbon
    {
        return $registration?->bedSelectionDeadline();
    }

    private function notifier(): StudentNotificationService
    {
        return app(StudentNotificationService::class);
    }

    private function pickRoom(
        array  &$roomMap,
        array   $roomTrack,
        string  $gender,
        string  $province,
        string  $faculty,
        string  $courseYear,
        ?int    $preferredFloor
    ): ?int {
        $candidates = array_filter($roomMap, fn ($info) =>
            $info['remaining'] > 0 &&
            ($info['gender'] === $gender || $info['gender'] === '')
        );

        if (empty($candidates)) return null;

        $scored = [];
        foreach ($candidates as $roomId => $info) {
            $track = $roomTrack[$roomId] ?? [];
            $score = 0;

            if ($province !== '' && isset($track['province'][$province])) {
                $score += 100;
            }
            if (isset($track['faculty'][$faculty]) && isset($track['year'][$courseYear])) {
                $score += 20;
            } elseif (isset($track['faculty'][$faculty])) {
                $score += 10;
            }
            if ($preferredFloor !== null && $info['floor_id'] === $preferredFloor) {
                $score += 50;
            }
            $fillRatio = ($info['active_beds'] > 0)
                ? ($info['active_beds'] - $info['remaining']) / $info['active_beds']
                : 0;
            $score += (int) round($fillRatio * 10);

            $scored[$roomId] = $score;
        }

        arsort($scored);
        $best     = array_key_first($scored);
        $topScore = $scored[$best];
        $tied     = array_keys(array_filter($scored, fn ($s) => $s === $topScore));

        if (count($tied) > 1) {
            usort($tied, fn ($a, $b) =>
                $candidates[$a]['remaining'] <=> $candidates[$b]['remaining']
                ?: $a <=> $b
            );
            return $tied[0];
        }

        return $best;
    }
}
