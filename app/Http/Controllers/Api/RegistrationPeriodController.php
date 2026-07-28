<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Services\DormCapacityService;
use App\Services\PriorityRankingService;
use App\Services\StudentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegistrationPeriodController extends Controller
{
    private const MODIFICATION_LOCKED_MESSAGE = 'Không thể sửa hoặc xóa đợt đã mở/đã đóng. Vui lòng tạo đợt mới nếu cần thay đổi.';

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
            // Tách riêng đơn SV tự hủy (status 'cancelled') khỏi "Tổng đơn" hiển thị cùng
            // approved/rejected — trước đây "Tổng đơn" đếm cả cancelled trong khi 2 badge
            // approved/rejected không tính, khiến tổng 2 badge luôn lệch với "Tổng đơn" hiển
            // thị, gây hiểu lầm là thiếu đơn (báo cáo 28/07).
            'registrations as cancelled_count'        => fn ($q) => $q->where('status', 'cancelled'),
            'registrations as approve_proposal_count' => fn ($q) => $q->where('auto_decision', 'approve')->where('status', 'submitted'),
            'registrations as reject_proposal_count'  => fn ($q) => $q->where('auto_decision', 'reject')->where('status', 'submitted'),
            'registrations as review_count'           => fn ($q) => $q->where('auto_decision', 'review')->where('status', 'submitted'),
            // Tách riêng theo giới tính — giường KTX tách riêng theo giới ở cấp Floor, nên
            // "đang đề xuất duyệt" cũng phải xét riêng từng giới khi kiểm tra vượt suất (xem
            // PriorityRankingService::rankPeriod()), không dùng được 1 số gộp chung nữa.
            'registrations as approve_proposal_count_male' => fn ($q) => $q->where('auto_decision', 'approve')->where('status', 'submitted')
                ->whereHas('student', fn ($sq) => $sq->where('gender', 'male')),
            'registrations as approve_proposal_count_female' => fn ($q) => $q->where('auto_decision', 'approve')->where('status', 'submitted')
                ->whereHas('student', fn ($sq) => $sq->where('gender', 'female')),
            // Đếm riêng cho luồng tân sinh viên — dùng hiển thị trạng thái đóng đợt tự động,
            // không phục vụ chốt thủ công (đã bỏ cơ chế đó).
            //
            // Tách "chưa xếp hạng" (submitted/waitlisted, auto_decision null — cần bấm Xếp
            // hạng) khỏi "đã có gợi ý, chờ xác nhận" (đã qua rankReservationPeriod() nhưng
            // chưa được admin bấm Xác nhận đề xuất) — trước đây gộp chung vào "Chờ xét" khiến
            // hồ sơ ĐÃ xếp hạng vẫn bị nhắc "nhập danh sách nhập học/MSSV", sai hành động cần
            // làm thật sự (chỉ cần bấm Xác nhận đề xuất, không liên quan nhập học) — báo cáo
            // 24/07.
            'dormReservations as admission_submitted_count'  => fn ($q) => $q->where('status', 'submitted')->whereNull('auto_decision'),
            'dormReservations as admission_waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted')->whereNull('auto_decision'),
            'dormReservations as admission_awaiting_confirm_count' => fn ($q) => $q->whereIn('status', ['submitted', 'waitlisted'])->whereNotNull('auto_decision'),
            // Chỉ tính candidate CHƯA nhập học — số này chỉ để hiển thị cảnh báo nhắc admin
            // nhập danh sách nhập học/MSSV, KHÔNG còn chặn xếp hạng (xem process() bên dưới).
            'dormReservations as admission_approved_count'   => fn ($q) => $q->where('status', 'approved')
                ->whereHas('candidate', fn ($cq) => $cq->where('status', '!=', 'enrolled')),
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

        // Không tự đóng đợt kênh 'main' nếu còn đơn 'submitted' chưa xử lý — tránh đá nhau
        // với AutoCloseAdmissionPeriodsCommand (lệnh xử lý thật: tự xếp hạng/xác nhận, hoặc
        // báo admin nếu còn minh chứng chưa xác minh và GIỮ đợt ở active). Nếu hàm này cứ
        // đóng theo ngày bất kể còn việc dở, mỗi lần ai đó load lại trang sẽ xóa sạch trạng
        // thái "chưa đóng" mà lệnh kia vừa set, tạo vòng lặp mở-đóng vô nghĩa mỗi lần
        // refresh (báo cáo 27/07). Đợt kênh 'rolling' không qua process()/confirmBatch() nên
        // không tính, cứ đóng theo ngày như cũ.
        RegistrationPeriod::where('status', 'active')
            ->whereDate('end_date', '<', $today)
            ->where(function ($q) {
                $q->where('channel', '!=', 'main')
                    ->orWhereDoesntHave('registrations', fn ($rq) => $rq->where('status', 'submitted'));
            })
            ->update(['status' => 'closed']);
    }

    public function show(int $id): JsonResponse
    {
        $period = $this->withBreakdownCounts()->findOrFail($id);
        return response()->json($period);
    }

    private function validationRules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:191'],
            'channel'            => ['required', Rule::in(['main', 'rolling'])],
            'status'             => ['sometimes', Rule::in(['pending', 'active', 'closed', 'processing'])],
            'school_year'        => ['required', 'string', 'max:20', 'regex:/^\d{4}-\d{4}$/'],
            'semester'           => ['required', 'string', Rule::in(['1', '2', '3'])],
            'start_date'         => ['required', 'date'],
            'end_date'           => ['required', 'date', 'after_or_equal:start_date'],
            'stay_start_date'    => ['required', 'date'],
            'stay_end_date'      => ['required', 'date', 'after:stay_start_date'],
            'bed_selection_days'        => ['required', 'integer', 'min:1'],
            'processing_days'           => ['required', 'integer', 'min:1'],
            'initial_payment_due_days'  => ['required', 'integer', 'min:1'],
            'round_number'              => ['nullable', 'integer', 'min:1'],
            'allow_admission_candidates' => ['required', 'boolean'],
            'requires_student_code'     => ['required', 'boolean'],
        ];
    }

    private function validationMessages(): array
    {
        return [
            'name.required'              => 'Vui lòng nhập tên đợt.',
            'channel.required'           => 'Vui lòng chọn kênh.',
            'channel.in'                 => 'Kênh không hợp lệ.',
            'school_year.required'       => 'Vui lòng nhập năm học.',
            'school_year.regex'          => 'Năm học phải có định dạng YYYY-YYYY.',
            'semester.required'          => 'Vui lòng chọn học kỳ.',
            'semester.in'                => 'Học kỳ không hợp lệ.',
            'start_date.required'        => 'Vui lòng chọn ngày bắt đầu.',
            'start_date.date'            => 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.',
            'end_date.required'          => 'Vui lòng chọn ngày kết thúc.',
            'end_date.date'              => 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.',
            'end_date.after_or_equal'    => 'Ngày kết thúc phải sau ngày bắt đầu.',
            'stay_start_date.required'   => 'Vui lòng chọn ngày bắt đầu.',
            'stay_start_date.date'       => 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.',
            'stay_end_date.required'     => 'Vui lòng chọn ngày kết thúc.',
            'stay_end_date.date'         => 'Vui lòng nhập ngày theo định dạng dd/mm/yyyy.',
            'stay_end_date.after'        => 'Ngày kết thúc phải sau ngày bắt đầu.',
            'processing_days.required'   => 'Vui lòng nhập số ngày xử lý.',
            'processing_days.integer'    => 'Số ngày phải là số nguyên.',
            'processing_days.min'        => 'Số ngày phải lớn hơn hoặc bằng 1.',
            'bed_selection_days.required' => 'Vui lòng nhập số ngày chọn giường.',
            'bed_selection_days.integer' => 'Số ngày phải là số nguyên.',
            'bed_selection_days.min'     => 'Số ngày phải lớn hơn hoặc bằng 1.',
            'initial_payment_due_days.required' => 'Vui lòng nhập số ngày thanh toán.',
            'initial_payment_due_days.integer'  => 'Số ngày phải là số nguyên.',
            'initial_payment_due_days.min'      => 'Số ngày phải lớn hơn hoặc bằng 1.',
            'allow_admission_candidates.required' => 'Vui lòng chọn đối tượng đăng ký.',
            'allow_admission_candidates.boolean'  => 'Đối tượng đăng ký không hợp lệ.',
            'requires_student_code.required'      => 'Vui lòng chọn đối tượng đăng ký.',
            'requires_student_code.boolean'       => 'Đối tượng đăng ký không hợp lệ.',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->validationRules(), $this->validationMessages());

        $semesterEndError = $this->validateSemesterIsNotEnded($data['school_year'], $data['semester']);
        if ($semesterEndError) {
            return response()->json([
                'message' => $semesterEndError['message'],
                'errors'  => [$semesterEndError['field'] => [$semesterEndError['message']]],
            ], 422);
        }

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

        if (! $this->canModifyPeriod($period)) {
            return response()->json(['message' => self::MODIFICATION_LOCKED_MESSAGE], 422);
        }

        $data = $request->validate($this->validationRules(), $this->validationMessages());

        $channel    = $data['channel']     ?? $period->channel;
        $schoolYear = $data['school_year'] ?? $period->school_year;
        $semester   = $data['semester']    ?? $period->semester;
        $startDate  = $data['start_date']  ?? $period->start_date?->toDateString();
        $endDate    = $data['end_date']    ?? $period->end_date?->toDateString();

        $semesterEndError = $this->validateSemesterIsNotEnded($schoolYear, $semester);
        if ($semesterEndError) {
            return response()->json([
                'message' => $semesterEndError['message'],
                'errors'  => [$semesterEndError['field'] => [$semesterEndError['message']]],
            ], 422);
        }

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

        if (! $this->canModifyPeriod($period)) {
            return response()->json(['message' => self::MODIFICATION_LOCKED_MESSAGE], 422);
        }

        $period->delete();

        return response()->json(['message' => 'Đã xóa đợt đăng ký.']);
    }

    private function canModifyPeriod(RegistrationPeriod $period): bool
    {
        if ($period->status !== 'pending' || ! $period->start_date) {
            return false;
        }

        return \Carbon\Carbon::parse($period->start_date)->startOfDay()->gt(\Carbon\Carbon::today());
    }

    public function process(int $id): JsonResponse
    {
        // Lock RegistrationPeriod TRƯỚC (đúng lock order chung) — 2 request process() cùng
        // đợt tự serialize qua lock này; request sau đọc lại dữ liệu MỚI bên trong transaction
        // của nó (không ghi đè bằng dữ liệu cũ). Không sửa PriorityRankingService.
        $result = DB::transaction(function () use ($id) {
            $period = RegistrationPeriod::where('id', $id)->lockForUpdate()->first();

            if (!$period) {
                return ['ok' => false, 'status' => 404, 'payload' => ['message' => 'Không tìm thấy đợt đăng ký.']];
            }

            if ($period->channel !== 'main') {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Chỉ áp dụng cho kênh chính (main).']];
            }

            if (!in_array($period->status, ['active', 'closed', 'processing'], true)) {
                return ['ok' => false, 'status' => 422, 'payload' => ['message' => 'Đợt phải ở trạng thái active, closed hoặc processing.']];
            }

            // Không còn chặn xếp hạng vì còn hồ sơ giữ chỗ approved-chưa-nhập-học — sinh viên
            // trúng tuyển nhưng bỏ không nhập học thì sẽ KHÔNG BAO GIỜ hết trạng thái này,
            // chặn cứng ở đây sẽ khiến đợt không bao giờ xếp hạng được. Cảnh báo vẫn hiển thị
            // ở FE (chỉ để nhắc admin nhập danh sách nhập học/MSSV), không còn ngăn hành động.

            // Giường KTX tách riêng theo giới tính ở cấp Floor — tính suất trống RIÊNG cho
            // từng giới, không còn dùng 1 con số gộp chung (báo cáo 27/07: gộp chung khiến
            // duyệt lố 1 giới trong khi giới kia còn dư).
            //
            // excludeReservationsWithRegistration: true — đây là bước XẾP HẠNG, hồ sơ giữ chỗ
            // đã có Registration (dù đang 'submitted') chính là người đang được xếp hạng ngay
            // trong bảng bên dưới — không trừ suất họ trước, tránh trừ 2 lần cùng 1 suất (báo
            // cáo 28/07).
            $capacityService = app(DormCapacityService::class);
            $capacityBeforeByGender = [
                'male' => $capacityService->summarizeForRegistrationPeriod($period, gender: 'male', excludeReservationsWithRegistration: true),
                'female' => $capacityService->summarizeForRegistrationPeriod($period, gender: 'female', excludeReservationsWithRegistration: true),
            ];

            // Thống nhất 24/07: hồ sơ giữ chỗ CHƯA nhập học (chưa có Registration thật) KHÔNG
            // được cạnh tranh trực tiếp trong bảng xếp hạng Đơn đăng ký — chưa hề có "đơn" nào
            // để mà cạnh tranh. Ngân sách ở đây dùng nguyên available_approval_slots (đã trừ
            // sẵn suất của nhóm approved-chưa-convert qua DormCapacityService — họ vẫn giữ
            // suất riêng, ngoài bảng này). Chỉ khi họ nhập học xong (có Registration) và admin
            // bấm "Xếp hạng lại", họ mới vào PriorityRankingService::rankPeriod() như mọi
            // Registration khác.
            $freeBedsByGender = [
                'male' => (int) $capacityBeforeByGender['male']['available_approval_slots'],
                'female' => (int) $capacityBeforeByGender['female']['available_approval_slots'],
            ];

            $pendingCriteriaCount = \App\Models\StudentPriority::query()
                ->whereHas('registration', fn ($q) => $q->where('registration_period_id', $period->id))
                ->where('status', 'pending')
                ->count();

            // Đồng bộ với quy ước bên DormReservationController::rankReservations() — chặn
            // xếp hạng cả đợt khi còn minh chứng ưu tiên chưa xác minh, tránh hồ sơ pending
            // bị tính tier=99 (như không có ưu tiên) rồi lỡ được đề xuất duyệt/waitlist sai.
            if ($pendingCriteriaCount > 0) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => [
                        'message'                => "Còn {$pendingCriteriaCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh hoặc từ chối tất cả minh chứng trước khi xếp hạng.",
                        'pending_priority_count' => $pendingCriteriaCount,
                    ],
                ];
            }

            // Đơn của sinh viên chưa có giới tính (không nên xảy ra vì Student.gender bắt
            // buộc — chỉ phòng vệ dữ liệu bất thường) sẽ không rơi vào bucket nam/nữ nào, bị
            // loại âm thầm khỏi cả 2 bên duyệt/waitlist — chặn sớm để admin biết mà xử lý tay,
            // không để mất tích khỏi bảng xếp hạng mà không ai hay.
            $genderlessCount = Registration::where('registration_period_id', $period->id)
                ->whereDoesntHave('studentPriorities', fn ($q) => $q->where('status', 'rejected'))
                ->whereHas('student', fn ($q) => $q->whereNotIn('gender', ['male', 'female'])->orWhereNull('gender'))
                ->count();
            if ($genderlessCount > 0) {
                return [
                    'ok'      => false,
                    'status'  => 422,
                    'payload' => [
                        'message' => "Còn {$genderlessCount} đơn của sinh viên chưa xác định giới tính hợp lệ. Vui lòng kiểm tra lại hồ sơ sinh viên trước khi xếp hạng.",
                    ],
                ];
            }

            $ranker = new PriorityRankingService();
            $rankResult = $ranker->rankPeriod($period->id, $freeBedsByGender, recalculate: true);

            foreach ($rankResult['approved'] as $reg) {
                $reg->auto_decision = 'approve';
                $reg->auto_decision_reason = null;
                $reg->save();
            }

            $genderLabel = ['male' => 'nam', 'female' => 'nữ'];
            foreach (['male', 'female'] as $gender) {
                $genderBucket = $rankResult['byGender'][$gender];
                $genderApprovedCount = $genderBucket['approved']->count();
                $genderTotal = $genderApprovedCount + $genderBucket['waitlist']->count();
                foreach ($genderBucket['waitlist'] as $index => $reg) {
                    $rank = $genderApprovedCount + $index + 1;
                    $reg->auto_decision = 'reject';
                    $reg->auto_decision_reason = "Không đủ chỉ tiêu ({$genderLabel[$gender]}) — xếp hạng thứ {$rank}/{$genderTotal}, chỉ tiêu {$freeBedsByGender[$gender]} suất.";
                    $reg->save();
                }
            }

            // Suất DƯ (nếu bảng Đơn đăng ký không dùng hết ngân sách) mới được xét đôn cho hồ
            // sơ giữ chỗ đang waitlisted (chưa nhập học) — luôn ưu tiên người có Registration
            // thật trước, đôn theo tier/điểm/mốc nộp riêng của nhóm giữ chỗ, và riêng theo
            // đúng giới của suất dư đó.
            $leftoverBedsByGender = [
                'male' => max(0, $freeBedsByGender['male'] - $rankResult['byGender']['male']['approved']->count()),
                'female' => max(0, $freeBedsByGender['female'] - $rankResult['byGender']['female']['approved']->count()),
            ];
            $promotedReservations = $ranker->rankLeftoverForWaitlistedReservations($period->id, $leftoverBedsByGender);

            $reservationsToNotifyApproved = [];
            foreach ($promotedReservations as $reservation) {
                $reservation->update([
                    'status'               => 'approved',
                    'approved_at'          => now(),
                    'auto_decision'        => null,
                    'auto_decision_reason' => null,
                ]);
                $reservationsToNotifyApproved[] = $reservation;
            }

            $period->status = 'processing';
            $period->save();

            $capacityAfterProposals = [
                'male' => $capacityService->summarizeForRegistrationPeriod(
                    $period,
                    $rankResult['byGender']['male']['approved']->count(),
                    gender: 'male',
                ),
                'female' => $capacityService->summarizeForRegistrationPeriod(
                    $period,
                    $rankResult['byGender']['female']['approved']->count(),
                    gender: 'female',
                ),
            ];

            return [
                'ok'                        => true,
                'free_beds_by_gender'       => $freeBedsByGender,
                'approved'                  => $rankResult['approved']->count(),
                'waitlist'                  => $rankResult['waitlist']->count(),
                'promoted_from_waitlist'    => count($reservationsToNotifyApproved),
                'pending_criteria_count'    => $pendingCriteriaCount,
                'capacity_by_gender'        => $capacityAfterProposals,
                'reservationsToNotifyApproved' => $reservationsToNotifyApproved,
            ];
        });

        if (!$result['ok']) {
            return response()->json($result['payload'], $result['status']);
        }

        // Gửi thông báo SAU khi transaction đã commit — candidate CHƯA nhập học nên chưa có
        // Student/Account, chỉ gửi email (giống notifyCandidate() bên DormReservationController).
        // Chỉ có chiều "được đôn lên approved" — hồ sơ giữ chỗ chưa nhập học không còn bị
        // chính vòng xếp hạng này đánh rớt nữa (họ không cạnh tranh trực tiếp ở đây).
        foreach ($result['reservationsToNotifyApproved'] as $reservation) {
            $candidate = $reservation->candidate;
            if ($candidate?->email) {
                app(StudentNotificationService::class)->notifyEmailOnly(
                    $candidate->email,
                    $candidate->full_name,
                    'Hồ sơ giữ chỗ KTX đã được duyệt',
                    'Hồ sơ đăng ký giữ chỗ KTX của bạn đã được đôn lên duyệt vì còn suất trống sau khi xếp hạng đợt chính. Vui lòng hoàn tất thủ tục nhập học theo hướng dẫn.',
                    queue: true,
                );
            }
        }

        return response()->json([
            'message'                => 'Đã xếp hạng xong.',
            'free_beds_by_gender'    => $result['free_beds_by_gender'],
            'approved'               => $result['approved'],
            'waitlist'               => $result['waitlist'],
            'promoted_from_waitlist' => $result['promoted_from_waitlist'],
            'pending_criteria_count' => $result['pending_criteria_count'],
            'capacity_by_gender'     => $result['capacity_by_gender'],
        ]);
    }

    public function capacity(int $id, Request $request): JsonResponse
    {
        $period = RegistrationPeriod::findOrFail($id);
        $capacityService = app(DormCapacityService::class);

        // Giường tách riêng theo giới tính — số đơn "đang đề xuất duyệt" truyền vào cũng phải
        // tách riêng theo giới, không còn 1 số gộp chung (xem PriorityRankingService::rankPeriod()).
        $proposedApprovedCountMale = max(0, (int) $request->query('proposed_approved_count_male', 0));
        $proposedApprovedCountFemale = max(0, (int) $request->query('proposed_approved_count_female', 0));

        return response()->json([
            'capacity' => [
                'male' => $capacityService->summarizeForRegistrationPeriod($period, $proposedApprovedCountMale, gender: 'male'),
                'female' => $capacityService->summarizeForRegistrationPeriod($period, $proposedApprovedCountFemale, gender: 'female'),
            ],
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

    /**
     * @return array{field: string, message: string}|null
     */
    private function validateSemesterIsNotEnded(?string $schoolYear, ?string $semester): ?array
    {
        $schoolYear = trim((string) $schoolYear);
        if (! preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches)) {
            return [
                'field'   => 'school_year',
                'message' => 'Năm học phải có định dạng YYYY-YYYY.',
            ];
        }

        $startYear = (int) $matches[1];
        $endYear   = (int) $matches[2];
        if ($endYear !== $startYear + 1) {
            return [
                'field'   => 'school_year',
                'message' => 'Năm học phải có định dạng YYYY-YYYY.',
            ];
        }

        $today = \Carbon\Carbon::now()->startOfDay();
        $schoolYearEndDate = \Carbon\Carbon::create($endYear, 8, 31)->startOfDay();
        if ($schoolYearEndDate->lt($today)) {
            return [
                'field'   => 'school_year',
                'message' => 'Không thể tạo hoặc sửa đợt đăng ký cho năm học đã kết thúc.',
            ];
        }

        $semesterEndDate = $this->semesterEndDate($schoolYear, $semester);
        if (! $semesterEndDate) {
            return [
                'field'   => $semesterEndDate === false ? 'semester' : 'school_year',
                'message' => $semesterEndDate === false
                    ? 'Học kỳ không hợp lệ.'
                    : 'Năm học phải có định dạng YYYY-YYYY.',
            ];
        }

        if ($semesterEndDate->lt($today)) {
            return [
                'field'   => 'semester',
                'message' => 'Không thể tạo hoặc sửa đợt đăng ký cho học kỳ đã kết thúc.',
            ];
        }

        return null;
    }

    private function semesterEndDate(?string $schoolYear, ?string $semester): \Carbon\Carbon|false|null
    {
        $schoolYear = trim((string) $schoolYear);
        if (! preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches)) {
            return null;
        }

        $startYear = (int) $matches[1];
        $endYear   = (int) $matches[2];
        if ($endYear !== $startYear + 1) {
            return null;
        }

        return match (trim((string) $semester)) {
            '1' => \Carbon\Carbon::create($startYear, 12, 31)->startOfDay(),
            '2' => \Carbon\Carbon::create($endYear, 5, 31)->startOfDay(),
            '3' => \Carbon\Carbon::create($endYear, 8, 31)->startOfDay(),
            default => false,
        };
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
