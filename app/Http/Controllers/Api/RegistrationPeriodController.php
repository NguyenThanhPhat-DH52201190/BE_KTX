<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\Occupancy;
use App\Models\RegistrationPeriod;
use App\Services\PriorityRankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegistrationPeriodController extends Controller
{
    private function withBreakdownCounts()
    {
        $pendingCriteriaSubquery = DB::table('student_priority')
            ->selectRaw('COUNT(*)')
            ->join('registrations', 'student_priority.registration_id', '=', 'registrations.id')
            ->whereColumn('registrations.registration_period_id', 'registration_periods.id')
            ->where('student_priority.status', 'pending');

        return RegistrationPeriod::withCount([
            'registrations',
            'registrations as approved_count'         => fn ($q) => $q->where('status', 'approved'),
            'registrations as rejected_count'         => fn ($q) => $q->where('status', 'rejected'),
            'registrations as approve_proposal_count' => fn ($q) => $q->where('auto_decision', 'approve')->where('status', 'submitted'),
            'registrations as reject_proposal_count'  => fn ($q) => $q->where('auto_decision', 'reject')->where('status', 'submitted'),
            'registrations as review_count'           => fn ($q) => $q->where('auto_decision', 'review')->where('status', 'submitted'),
        ])->addSelect(DB::raw("({$pendingCriteriaSubquery->toSql()}) as `pending_criteria_count`"))
          ->addBinding($pendingCriteriaSubquery->getBindings(), 'select');
    }

    public function index(Request $request): JsonResponse
    {
        $this->syncPeriodStatuses();

        $query = $this->withBreakdownCounts()->orderByDesc('created_at');

        return response()->json($query->get());
    }

    private function syncPeriodStatuses(): void
    {
        $today = \Carbon\Carbon::today();

        RegistrationPeriod::where('status', 'pending')
            ->whereDate('start_date', '<=', $today)
            ->update(['status' => 'active']);

        RegistrationPeriod::where('status', 'active')
            ->whereDate('end_date', '<', $today)
            ->update(['status' => 'closed']);
    }

    public function show(int $id): JsonResponse
    {
        $period = $this->withBreakdownCounts()->findOrFail($id);
        return response()->json($period);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:191'],
            'channel'            => ['required', Rule::in(['main', 'rolling'])],
            'school_year'        => ['required', 'string', 'max:20'],
            'semester'           => ['required', 'string', 'max:20'],
            'start_date'         => ['required', 'date'],
            'end_date'           => ['required', 'date', 'after_or_equal:start_date'],
            'stay_start_date'    => ['nullable', 'date'],
            'stay_end_date'      => ['nullable', 'date', 'after_or_equal:stay_start_date'],
            'bed_selection_days'        => ['nullable', 'integer', 'min:0'],
            'processing_days'           => ['nullable', 'integer', 'min:1'],
            'initial_payment_due_days'  => ['required', 'integer', 'min:1'],
            'round_number'              => ['nullable', 'integer', 'min:1'],
            'allow_admission_candidates' => ['sometimes', 'boolean'],
            'requires_student_code'     => ['sometimes', 'boolean'],
        ]);

        // Rule 1: Mỗi năm học chỉ được có 1 đợt chính
        if ($data['channel'] === 'main') {
            $existing = RegistrationPeriod::where('channel', 'main')
                ->where('school_year', $data['school_year'])
                ->first();
            if ($existing) {
                $msg = "Năm học {$data['school_year']} đã có đợt chính rồi, không thể tạo thêm.";
                return response()->json([
                    'message' => $msg,
                    'errors'  => ['channel' => [$msg]],
                ], 422);
            }
        }

        // Rule 2: Kênh quanh năm chỉ được mở sau khi đợt chính đã đóng
        if ($data['channel'] === 'rolling') {
            $mainClosed = RegistrationPeriod::where('channel', 'main')
                ->where('school_year', $data['school_year'])
                ->where('status', 'closed')
                ->exists();
            if (!$mainClosed) {
                $msg = 'Kênh quanh năm chỉ được mở sau khi đợt chính đã đóng.';
                return response()->json([
                    'message' => $msg,
                    'errors'  => ['channel' => [$msg]],
                ], 422);
            }
        }

        // Rule 3: Thời gian nhận đơn không được trùng
        $overlap = RegistrationPeriod::whereIn('status', ['active', 'pending'])
            ->where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date'])
            ->first();
        if ($overlap) {
            $msg = "Thời gian nhận đơn trùng với đợt '{$overlap->name}' đang hoạt động.";
            return response()->json([
                'message' => $msg,
                'errors'  => ['start_date' => [$msg]],
            ], 422);
        }

        // Rule 4b: đợt chính (main) MỚI phải nối tiếp ngay sau khi lứa sinh viên trước đó kết
        // thúc lưu trú — tránh khoảng trống/chồng lấn giữa 2 lứa. Ghi đè stay_start_date, không
        // cho nhập tay (chỉ áp dụng khi đã có dữ liệu lưu trú trước đó để tham chiếu; đợt chính
        // đầu tiên chưa có gì để nối tiếp thì admin vẫn tự chọn như cũ).
        if ($data['channel'] === 'main') {
            $previousStayEnd = RegistrationPeriod::currentStayEndDate();
            if ($previousStayEnd) {
                $data['stay_start_date'] = \Carbon\Carbon::parse($previousStayEnd)->addDay()->toDateString();
            }
        }

        // Rule 4: stay_start_date >= end_date + processing_days + bed_selection_days + initial_payment_due_days
        if (!empty($data['stay_start_date'])) {
            $stayStartError = $this->validateStayStartDate(
                $data['end_date'],
                $data['processing_days'] ?? 0,
                $data['bed_selection_days'] ?? 0,
                $data['initial_payment_due_days'],
                $data['stay_start_date'],
            );
            if ($stayStartError) {
                return response()->json([
                    'message' => $stayStartError,
                    'errors'  => ['stay_start_date' => [$stayStartError]],
                ], 422);
            }
        }

        // Rule 5: đợt quanh năm chỉ được ở tới cùng mốc kết thúc lưu trú với đợt chính
        // cùng năm học (sinh viên đăng ký giữa năm vẫn theo chu kỳ năm học chung, không
        // được cộng thêm 1 năm kể từ ngày đăng ký) — ghi đè stay_end_date, không cho nhập tay.
        if ($data['channel'] === 'rolling') {
            $mainStayEndError = $this->applyRollingStayEndDate($data, $data['school_year']);
            if ($mainStayEndError) {
                return response()->json([
                    'message' => $mainStayEndError,
                    'errors'  => ['stay_end_date' => [$mainStayEndError]],
                ], 422);
            }
        }

        $today = \Carbon\Carbon::today()->toDateString();
        $data['status'] = ($data['start_date'] === $today) ? 'active' : 'pending';

        $period = RegistrationPeriod::create($data);

        return response()->json($period, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $period = RegistrationPeriod::findOrFail($id);

        $data = $request->validate([
            'name'               => ['sometimes', 'string', 'max:191'],
            'channel'            => ['sometimes', Rule::in(['main', 'rolling'])],
            'status'             => ['sometimes', Rule::in(['pending', 'active', 'closed', 'processing'])],
            'school_year'        => ['sometimes', 'string', 'max:20'],
            'semester'           => ['sometimes', 'string', 'max:20'],
            'start_date'         => ['sometimes', 'date'],
            'end_date'           => ['sometimes', 'date', 'after_or_equal:start_date'],
            'stay_start_date'    => ['nullable', 'date'],
            'stay_end_date'      => ['nullable', 'date'],
            'bed_selection_days'        => ['nullable', 'integer', 'min:0'],
            'processing_days'           => ['nullable', 'integer', 'min:1'],
            'initial_payment_due_days'  => ['sometimes', 'integer', 'min:1'],
            'round_number'              => ['nullable', 'integer', 'min:1'],
            'allow_admission_candidates' => ['sometimes', 'boolean'],
            'requires_student_code'     => ['sometimes', 'boolean'],
        ]);

        $channel    = $data['channel']     ?? $period->channel;
        $schoolYear = $data['school_year'] ?? $period->school_year;
        $startDate  = $data['start_date']  ?? $period->start_date?->toDateString();
        $endDate    = $data['end_date']    ?? $period->end_date?->toDateString();

        if ($channel === 'main') {
            $existing = RegistrationPeriod::where('channel', 'main')
                ->where('school_year', $schoolYear)
                ->where('id', '!=', $id)
                ->first();
            if ($existing) {
                $msg = "Năm học {$schoolYear} đã có đợt chính rồi, không thể tạo thêm.";
                return response()->json([
                    'message' => $msg,
                    'errors'  => ['channel' => [$msg]],
                ], 422);
            }
        }

        if ($channel === 'rolling') {
            $mainClosed = RegistrationPeriod::where('channel', 'main')
                ->where('school_year', $schoolYear)
                ->where('status', 'closed')
                ->exists();
            if (!$mainClosed) {
                $msg = 'Kênh quanh năm chỉ được mở sau khi đợt chính đã đóng.';
                return response()->json([
                    'message' => $msg,
                    'errors'  => ['channel' => [$msg]],
                ], 422);
            }
        }

        if ($startDate && $endDate) {
            $overlap = RegistrationPeriod::whereIn('status', ['active', 'pending'])
                ->where('id', '!=', $id)
                ->where('start_date', '<=', $endDate)
                ->where('end_date', '>=', $startDate)
                ->first();
            if ($overlap) {
                $msg = "Thời gian nhận đơn trùng với đợt '{$overlap->name}' đang hoạt động.";
                return response()->json([
                    'message' => $msg,
                    'errors'  => ['start_date' => [$msg]],
                ], 422);
            }
        }

        // Rule 4: stay_start_date >= end_date + processing_days + bed_selection_days + initial_payment_due_days
        $stayStart         = $data['stay_start_date']         ?? $period->stay_start_date?->toDateString();
        $effectiveEndDate  = $endDate;
        $effectiveProcDays = $data['processing_days']          ?? $period->processing_days          ?? 0;
        $effectiveBedDays  = $data['bed_selection_days']       ?? $period->bed_selection_days        ?? 0;
        $effectiveDueDays  = $data['initial_payment_due_days'] ?? $period->initial_payment_due_days;

        if ($stayStart && $effectiveEndDate && $effectiveDueDays !== null) {
            $stayStartError = $this->validateStayStartDate(
                $effectiveEndDate,
                (int) $effectiveProcDays,
                (int) $effectiveBedDays,
                (int) $effectiveDueDays,
                $stayStart,
            );
            if ($stayStartError) {
                return response()->json([
                    'message' => $stayStartError,
                    'errors'  => ['stay_start_date' => [$stayStartError]],
                ], 422);
            }
        }

        // Rule 5: đợt quanh năm luôn theo đúng stay_end_date của đợt chính cùng năm học.
        if ($channel === 'rolling') {
            $mainStayEndError = $this->applyRollingStayEndDate($data, $schoolYear, $id);
            if ($mainStayEndError) {
                return response()->json([
                    'message' => $mainStayEndError,
                    'errors'  => ['stay_end_date' => [$mainStayEndError]],
                ], 422);
            }
        }

        $stayEndDateChanged = $channel === 'main' && array_key_exists('stay_end_date', $data);

        if ($stayEndDateChanged) {
            DB::transaction(function () use ($period, $data, $schoolYear) {
                $period->update($data);

                // Đồng bộ lại cho mọi đợt quanh năm cùng năm học để không lệch mốc kết thúc lưu trú.
                RegistrationPeriod::where('channel', 'rolling')
                    ->where('school_year', $schoolYear)
                    ->update(['stay_end_date' => $data['stay_end_date']]);

                // Đồng bộ luôn check_out_date của các occupancy ĐÃ TỒN TẠI (xếp phòng/đang ở) thuộc
                // năm học này — nếu không, nhóm sinh viên xếp phòng TRƯỚC khi sửa vẫn giữ ngày cũ
                // trong khi nhóm xếp phòng SAU nhận ngày mới, tạo ra 2 nhóm cùng năm học nhưng lệch
                // ngày kết thúc lưu trú. Chỉ cập nhật occupancy chưa kết thúc (bỏ qua COMPLETED/
                // TERMINATED/CANCELLED vì họ đã xong, không còn liên quan).
                Occupancy::whereIn('status', Occupancy::OCCUPIED_BED_STATUSES)
                    ->whereHas('registration.period', fn ($q) => $q->where('school_year', $schoolYear))
                    ->update(['check_out_date' => $data['stay_end_date']]);
            });
        } else {
            $period->update($data);
        }

        if (in_array($period->status, ['pending', 'active'], true)) {
            $today     = \Carbon\Carbon::today();
            $startDate = \Carbon\Carbon::parse($period->start_date);
            $period->status = $startDate->lte($today) ? 'active' : 'pending';
            $period->save();
        }

        return response()->json($period);
    }

    public function destroy(int $id): JsonResponse
    {
        $period = RegistrationPeriod::withCount('registrations')->findOrFail($id);

        if ($period->registrations_count > 0) {
            return response()->json([
                'message' => 'Không thể xóa đợt đã có đơn đăng ký.',
            ], 422);
        }

        $period->delete();

        return response()->json(['message' => 'Đã xóa đợt đăng ký.']);
    }

    public function process(int $id): JsonResponse
    {
        $period = RegistrationPeriod::findOrFail($id);

        if ($period->channel !== 'main') {
            return response()->json(['message' => 'Chỉ áp dụng cho kênh chính (main).'], 422);
        }

        if (!in_array($period->status, ['active', 'closed', 'processing'], true)) {
            return response()->json(['message' => 'Đợt phải ở trạng thái active, closed hoặc processing.'], 422);
        }

        $totalAvailable = Bed::where('status', 'active')->count();
        $occupiedCount  = Occupancy::occupiedBedsQuery()->pluck('bed_id')->unique()->count();
        $freeBeds       = max(0, $totalAvailable - $occupiedCount);

        $pendingCriteriaCount = \App\Models\StudentPriority::query()
            ->whereHas('registration', fn ($q) => $q->where('registration_period_id', $period->id))
            ->where('status', 'pending')
            ->count();

        $ranker = new PriorityRankingService();
        $result = $ranker->rankPeriod($period->id, $freeBeds, recalculate: true);

        $totalApplicants = $result['approved']->count() + $result['waitlist']->count();
        foreach ($result['approved'] as $reg) {
            $reg->auto_decision = 'approve';
            $reg->auto_decision_reason = null;
            $reg->save();
        }
        foreach ($result['waitlist'] as $index => $reg) {
            $rank = $result['approved']->count() + $index + 1;
            $reg->auto_decision = 'reject';
            $reg->auto_decision_reason = "Không đủ chỉ tiêu — xếp hạng thứ {$rank}/{$totalApplicants}, chỉ tiêu {$freeBeds} suất.";
            $reg->save();
        }

        $period->status = 'processing';
        $period->save();

        return response()->json([
            'message'                => 'Đã xếp hạng xong.',
            'free_beds'              => $freeBeds,
            'approved'               => $result['approved']->count(),
            'waitlist'               => $result['waitlist']->count(),
            'pending_criteria_count' => $pendingCriteriaCount,
        ]);
    }

    /**
     * Ghi đè $data['stay_end_date'] bằng đúng stay_end_date của đợt chính (channel=main)
     * cùng school_year — đợt quanh năm không được tự chọn ngày kết thúc lưu trú riêng.
     *
     * @param array<string, mixed> $data Tham chiếu — sẽ bị sửa trực tiếp nếu hợp lệ.
     * @return string|null Thông báo lỗi nếu chưa thể xác định được mốc kết thúc, null nếu đã ghi đè thành công.
     */
    private function applyRollingStayEndDate(array &$data, string $schoolYear, ?int $excludeId = null): ?string
    {
        $mainPeriod = RegistrationPeriod::where('channel', 'main')
            ->where('school_year', $schoolYear)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();

        if (! $mainPeriod || ! $mainPeriod->stay_end_date) {
            return 'Đợt chính chưa có ngày kết thúc lưu trú, vui lòng cập nhật đợt chính trước khi mở đợt quanh năm.';
        }

        $data['stay_end_date'] = $mainPeriod->stay_end_date->toDateString();

        return null;
    }

    private function validateStayStartDate(
        string $endDate,
        int $processingDays,
        int $bedSelectionDays,
        int $initialPaymentDueDays,
        string $stayStartDate,
    ): ?string {
        $totalDays = $processingDays + $bedSelectionDays + $initialPaymentDueDays;
        $minDate   = \Carbon\Carbon::parse($endDate)->addDays($totalDays)->toDateString();

        if ($stayStartDate < $minDate) {
            $minFormatted = \Carbon\Carbon::parse($minDate)->format('d/m/Y');
            return "Ngày bắt đầu lưu trú tối thiểu phải là {$minFormatted} "
                . "(sau {$processingDays} ngày xử lý + {$bedSelectionDays} ngày chọn giường "
                . "+ {$initialPaymentDueDays} ngày thanh toán).";
        }

        return null;
    }
}
