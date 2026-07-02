<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdmissionCandidate;
use App\Models\Bed;
use App\Models\DormReservation;
use App\Models\Occupancy;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\ReservationPriority;
use App\Models\StudentPriority;
use App\Models\StudentPriorityEvidence;
use App\Services\PriorityRankingService;
use App\Services\StudentNotificationService;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DormReservationController extends Controller
{
    // =========================================================
    // Public routes
    // =========================================================

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admission_code' => ['required', 'string'],
            'date_of_birth'  => ['nullable', 'date'],
            'cccd'           => ['nullable', 'string'],
        ]);

        if (empty($data['date_of_birth']) && empty($data['cccd'])) {
            return response()->json([
                'message' => 'Vui lòng cung cấp ngày sinh hoặc CCCD để xác minh.',
                'errors'  => ['date_of_birth' => ['Cần nhập ngày sinh hoặc CCCD.']],
            ], 422);
        }

        $candidate = AdmissionCandidate::where('admission_code', $data['admission_code'])->first();

        if (!$candidate) {
            return response()->json([
                'message' => 'Không tìm thấy mã hồ sơ trúng tuyển. Vui lòng kiểm tra lại.',
            ], 404);
        }

        if ($candidate->status === 'cancelled') {
            return response()->json([
                'message' => 'Hồ sơ trúng tuyển này đã bị huỷ.',
            ], 422);
        }

        if ($candidate->status !== 'admitted') {
            return response()->json([
                'message' => 'Hồ sơ này không ở trạng thái trúng tuyển.',
            ], 422);
        }

        // Xác minh thông tin
        $matched = false;

        if (!empty($data['date_of_birth'])) {
            $inputDob = \Carbon\Carbon::parse($data['date_of_birth'])->format('Y-m-d');
            $candidateDob = \Carbon\Carbon::parse($candidate->date_of_birth)->format('Y-m-d');
            if ($inputDob === $candidateDob) {
                $matched = true;
            }
        }

        if (!$matched && !empty($data['cccd'])) {
            if ($candidate->cccd && trim($data['cccd']) === trim($candidate->cccd)) {
                $matched = true;
            }
        }

        if (!$matched) {
            return response()->json([
                'message' => 'Thông tin xác minh không khớp. Vui lòng kiểm tra lại ngày sinh hoặc CCCD.',
            ], 422);
        }

        return response()->json([
            'id'           => $candidate->id,
            'admission_code' => $candidate->admission_code,
            'full_name'    => $candidate->full_name,
            'date_of_birth' => $candidate->date_of_birth?->format('Y-m-d'),
            'major_name'   => $candidate->major_name,
            'course_year'  => $candidate->course_year,
            'school_year'  => $candidate->school_year,
            'gender'       => $candidate->gender,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admission_code'         => ['required', 'string'],
            'date_of_birth'          => ['nullable', 'date'],
            'cccd'                   => ['nullable', 'string'],
            'registration_period_id' => ['required', 'integer', 'exists:registration_periods,id'],
            'phone'                  => ['nullable', 'string', 'max:20'],
            'email'                  => ['nullable', 'email', 'max:191'],
            'priority_note'          => ['nullable', 'string', 'max:1000'],
            'father_name'            => ['nullable', 'string', 'max:191'],
            'father_birth_year'      => ['nullable', 'string', 'max:10'],
            'father_job'             => ['nullable', 'string', 'max:191'],
            'father_phone'           => ['nullable', 'string', 'max:20'],
            'mother_name'            => ['nullable', 'string', 'max:191'],
            'mother_birth_year'      => ['nullable', 'string', 'max:10'],
            'mother_job'             => ['nullable', 'string', 'max:191'],
            'mother_phone'           => ['nullable', 'string', 'max:20'],
            'parent_address'         => ['nullable', 'string'],
            'commitment_confirm'     => ['nullable', 'boolean'],
        ]);

        if (empty($data['date_of_birth']) && empty($data['cccd'])) {
            return response()->json([
                'message' => 'Vui lòng cung cấp ngày sinh hoặc CCCD để xác minh.',
            ], 422);
        }

        // Xác minh candidate
        $candidate = AdmissionCandidate::where('admission_code', $data['admission_code'])->first();

        if (!$candidate || $candidate->status !== 'admitted') {
            return response()->json(['message' => 'Mã hồ sơ trúng tuyển không hợp lệ hoặc không ở trạng thái trúng tuyển.'], 422);
        }

        $matched = false;
        if (!empty($data['date_of_birth'])) {
            $inputDob = \Carbon\Carbon::parse($data['date_of_birth'])->format('Y-m-d');
            if ($inputDob === \Carbon\Carbon::parse($candidate->date_of_birth)->format('Y-m-d')) {
                $matched = true;
            }
        }
        if (!$matched && !empty($data['cccd'])) {
            if ($candidate->cccd && trim($data['cccd']) === trim($candidate->cccd)) {
                $matched = true;
            }
        }
        if (!$matched) {
            return response()->json(['message' => 'Thông tin xác minh không khớp.'], 422);
        }

        // Kiểm tra đợt đăng ký
        $period = RegistrationPeriod::findOrFail($data['registration_period_id']);

        if (!$period->allow_admission_candidates) {
            return response()->json(['message' => 'Đợt đăng ký này không cho phép hồ sơ giữ chỗ tân sinh viên.'], 422);
        }

        // Kiểm tra trùng hồ sơ active
        $existingActive = DormReservation::where('admission_candidate_id', $candidate->id)
            ->where('registration_period_id', $period->id)
            ->whereIn('status', ['submitted', 'approved', 'waitlisted'])
            ->first();

        if ($existingActive) {
            return response()->json([
                'message'     => 'Bạn đã có hồ sơ giữ chỗ đang hoạt động trong đợt này.',
                'reservation' => $existingActive,
            ], 422);
        }

        // Cập nhật thông tin liên hệ nếu candidate chưa có
        $updateCandidate = [];
        if (!empty($data['phone']) && !$candidate->phone) {
            $updateCandidate['phone'] = $data['phone'];
        }
        if (!empty($data['email']) && !$candidate->email) {
            $updateCandidate['email'] = $data['email'];
        }
        if ($updateCandidate) {
            $candidate->update($updateCandidate);
        }

        // Tạo reservation
        $reservation = DormReservation::create([
            'admission_candidate_id' => $candidate->id,
            'registration_period_id' => $period->id,
            'reservation_code'       => $this->generateReservationCode(),
            'status'                 => 'submitted',
            'priority_note'          => $data['priority_note'] ?? null,
            'father_name'            => $data['father_name'] ?? null,
            'father_birth_year'      => $data['father_birth_year'] ?? null,
            'father_job'             => $data['father_job'] ?? null,
            'father_phone'           => $data['father_phone'] ?? null,
            'mother_name'            => $data['mother_name'] ?? null,
            'mother_birth_year'      => $data['mother_birth_year'] ?? null,
            'mother_job'             => $data['mother_job'] ?? null,
            'mother_phone'           => $data['mother_phone'] ?? null,
            'parent_address'         => $data['parent_address'] ?? null,
            'commitment_confirm'     => $data['commitment_confirm'] ?? false,
            'submitted_at'           => now(),
        ]);

        return response()->json([
            'message'      => 'Đã gửi hồ sơ giữ chỗ thành công.',
            'reservation'  => $reservation->load('candidate', 'period'),
        ], 201);
    }

    // =========================================================
    // Admin routes
    // =========================================================

    public function index(Request $request): JsonResponse
    {
        $query = DormReservation::with(['candidate', 'period'])
            ->orderByDesc('created_at');

        if ($s = $request->input('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('reservation_code', 'like', "%{$s}%")
                  ->orWhere('student_code', 'like', "%{$s}%")
                  ->orWhereHas('candidate', function ($cq) use ($s) {
                      $cq->where('admission_code', 'like', "%{$s}%")
                         ->orWhere('full_name', 'like', "%{$s}%")
                         ->orWhere('cccd', 'like', "%{$s}%")
                         ->orWhere('phone', 'like', "%{$s}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($periodId = $request->input('registration_period_id')) {
            $query->where('registration_period_id', $periodId);
        }

        return response()->json($query->paginate(20));
    }

    public function show(int $id): JsonResponse
    {
        $reservation = DormReservation::with([
            'candidate.student',
            'period',
            'convertedRegistration',
            'reservationPriorities.criteria',
            'reservationPriorities.evidences',
        ])->findOrFail($id);

        return response()->json($reservation);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if (!in_array($reservation->status, ['submitted', 'waitlisted'], true)) {
            return response()->json(['message' => 'Chỉ duyệt được hồ sơ ở trạng thái đã nộp hoặc đang chờ.'], 422);
        }

        $reservation->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'admin_note'  => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đã được duyệt',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn đã được duyệt. Vui lòng theo dõi email/thông báo để hoàn tất thủ tục nhập học và đăng ký lưu trú chính thức.',
        );

        return response()->json(['message' => 'Đã duyệt hồ sơ giữ chỗ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if (!in_array($reservation->status, ['submitted', 'waitlisted', 'approved'], true)) {
            return response()->json(['message' => 'Không thể từ chối hồ sơ ở trạng thái hiện tại.'], 422);
        }

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $reservation->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
            'admin_note'       => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX bị từ chối',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị từ chối. Lý do: ' . $data['rejection_reason'],
        );

        return response()->json(['message' => 'Đã từ chối hồ sơ giữ chỗ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function waitlist(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if ($reservation->status !== 'submitted') {
            return response()->json(['message' => 'Chỉ chuyển được hồ sơ đã nộp vào danh sách chờ.'], 422);
        }

        $reservation->update([
            'status'     => 'waitlisted',
            'admin_note' => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đang chờ xét duyệt',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn hiện đang ở danh sách chờ do số lượng chỗ có hạn. Vui lòng theo dõi thông báo tiếp theo.',
        );

        return response()->json(['message' => 'Đã chuyển vào danh sách chờ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::with('candidate')->findOrFail($id);

        if (in_array($reservation->status, ['converted', 'cancelled'], true)) {
            return response()->json(['message' => 'Hồ sơ đã được chuyển đổi hoặc đã huỷ, không thể huỷ lại.'], 422);
        }

        $reservation->update([
            'status'     => 'cancelled',
            'admin_note' => $request->input('admin_note', $reservation->admin_note),
        ]);

        $this->notifyCandidate(
            $reservation->candidate,
            'Hồ sơ giữ chỗ KTX đã bị huỷ',
            'Hồ sơ đăng ký giữ chỗ KTX của bạn đã bị huỷ.',
        );

        return response()->json(['message' => 'Đã huỷ hồ sơ giữ chỗ.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $reservation = DormReservation::findOrFail($id);

        $data = $request->validate([
            'admin_note' => ['required', 'string', 'max:2000'],
        ]);

        $reservation->update(['admin_note' => $data['admin_note']]);

        return response()->json(['message' => 'Đã cập nhật ghi chú.', 'reservation' => $reservation->load('candidate', 'period')]);
    }

    public function convertToRegistration(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'registration_period_id' => ['nullable', 'integer', 'exists:registration_periods,id'],
        ]);

        return DB::transaction(function () use ($id, $data) {
            $reservation = DormReservation::with('candidate')->findOrFail($id);

            if ($reservation->status === 'converted') {
                return response()->json(['message' => 'Hồ sơ này đã được chuyển đổi thành đơn KTX rồi.'], 422);
            }

            if (!in_array($reservation->status, ['submitted', 'approved', 'waitlisted'], true)) {
                return response()->json(['message' => 'Chỉ chuyển đổi được hồ sơ ở trạng thái đã nộp, đã duyệt hoặc đang chờ.'], 422);
            }

            $candidate = $reservation->candidate;

            if ($candidate->status !== 'enrolled' || !$candidate->student_id) {
                return response()->json(['message' => 'Thí sinh chưa được chuyển thành sinh viên chính thức. Vui lòng xác nhận nhập học trước.'], 422);
            }

            // Xác định period
            $periodId = $data['registration_period_id'] ?? $reservation->registration_period_id;

            if (!$periodId) {
                return response()->json(['message' => 'Vui lòng cung cấp đợt đăng ký để chuyển đổi hồ sơ.'], 422);
            }

            $period = RegistrationPeriod::findOrFail($periodId);
            $studentId = $candidate->student_id;

            // Kiểm tra không tạo trùng registration cho cùng student + period
            $existingReg = Registration::where('student_id', $studentId)
                ->where('registration_period_id', $periodId)
                ->whereNotIn('status', ['rejected'])
                ->first();

            if ($existingReg) {
                return response()->json([
                    'message'         => 'Sinh viên đã có đơn đăng ký KTX trong đợt này.',
                    'registration_id' => $existingReg->id,
                ], 422);
            }

            // Xác định status registration
            $regStatus = ($reservation->status === 'approved') ? 'approved' : 'submitted';
            $approvedAt = ($regStatus === 'approved') ? now() : null;


            $registration = Registration::create([
                'student_id'             => $studentId,
                'registration_period_id' => $periodId,
                'semester'               => $period->semester,
                'school_year'            => $period->school_year,
                'stay_from_date'         => $period->stay_start_date?->format('Y-m-d'),
                'stay_to_date'           => $period->stay_end_date?->format('Y-m-d'),
                'status'                 => $regStatus,
                'registration_type'      => 'new',
                'approved_at'            => $approvedAt,
                'note'                   => "Chuyển từ hồ sơ giữ chỗ #{$reservation->reservation_code}",
                'commitment_confirm'     => true,
                'avatar_url'             => $reservation->avatar_url,
                'cccd_front_url'         => $reservation->cccd_front_url,
                'cccd_back_url'          => $reservation->cccd_back_url,
                'father_name'            => $reservation->father_name,
                'father_birth_year'      => $reservation->father_birth_year,
                'father_job'             => $reservation->father_job,
                'father_phone'           => $reservation->father_phone,
                'mother_name'            => $reservation->mother_name,
                'mother_birth_year'      => $reservation->mother_birth_year,
                'mother_job'             => $reservation->mother_job,
                'mother_phone'           => $reservation->mother_phone,
                'parent_address'         => $reservation->parent_address,
                'top_priority_tier'      => $reservation->top_priority_tier,
                'total_priority_score'   => $reservation->total_priority_score,
            ]);

            // Copy tiêu chí ưu tiên sang registration
            $this->copyPrioritiesToRegistration($reservation, $candidate->student_id, $registration->id);

            // Cập nhật reservation
            $reservation->update([
                'status'                  => 'converted',
                'converted_registration_id' => $registration->id,
            ]);

            // Hồ sơ giữ chỗ đã duyệt sẵn -> đơn nội trú tạo ra cũng approved luôn,
            // sinh viên (đã có tài khoản) cần được báo để chờ phân phòng.
            if ($regStatus === 'approved') {
                $student = Student::find($studentId);
                if ($student) {
                    app(StudentNotificationService::class)->notifyStudent(
                        $student,
                        'Đơn đăng ký nội trú đã được duyệt',
                        'Đơn đăng ký nội trú KTX của bạn đã được duyệt. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
                        'registration_approved',
                        $registration->id,
                    );
                }
            }

            return response()->json([
                'message'         => 'Đã chuyển đổi thành đơn KTX chính thức thành công.',
                'registration_id' => $registration->id,
                'registration'    => $registration,
                'reservation'     => $reservation->fresh(),
            ]);
        });
    }

    // =========================================================
    // Admin — ranking & batch convert
    // =========================================================

    /**
     * Xếp hạng tất cả hồ sơ submitted/waitlisted theo tiêu chí ưu tiên.
     * POST /admin/dorm-reservations/rank
     */
    public function rankReservations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_period_id' => ['required', 'integer', 'exists:registration_periods,id'],
        ]);

        $periodId = $data['registration_period_id'];

        $pendingCount = ReservationPriority::whereHas(
            'dormReservation',
            fn ($q) => $q->where('registration_period_id', $periodId)
        )->where('status', 'pending')->count();

        if ($pendingCount > 0) {
            return response()->json([
                'message'                => "Còn {$pendingCount} minh chứng ưu tiên chưa được xác minh. Vui lòng xác minh tất cả minh chứng trước khi xếp hạng.",
                'pending_priority_count' => $pendingCount,
            ], 422);
        }

        $totalAvailable = Bed::where('status', 'active')->count();
        $occupiedCount  = Occupancy::occupiedBedsQuery()->pluck('bed_id')->unique()->count();
        $freeBeds       = max(0, $totalAvailable - $occupiedCount);

        $ranker = new PriorityRankingService();
        $result = $ranker->rankReservationPeriod($periodId, $freeBeds);

        foreach ($result['approved'] as $reservation) {
            $reservation->update(['status' => 'approved', 'approved_at' => now()]);
            $this->notifyCandidate(
                $reservation->candidate,
                'Hồ sơ giữ chỗ KTX đã được duyệt',
                'Hồ sơ đăng ký giữ chỗ KTX của bạn đã được duyệt. Vui lòng theo dõi email/thông báo để hoàn tất thủ tục nhập học và đăng ký lưu trú chính thức.',
            );
        }
        foreach ($result['waitlist'] as $reservation) {
            $reservation->update(['status' => 'waitlisted']);
            $this->notifyCandidate(
                $reservation->candidate,
                'Hồ sơ giữ chỗ KTX đang chờ xét duyệt',
                'Hồ sơ đăng ký giữ chỗ KTX của bạn hiện đang ở danh sách chờ do số lượng chỗ có hạn. Vui lòng theo dõi thông báo tiếp theo.',
            );
        }

        return response()->json([
            'message'   => 'Đã xếp hạng xong.',
            'free_beds' => $freeBeds,
            'approved'  => $result['approved']->count(),
            'waitlist'  => $result['waitlist']->count(),
        ]);
    }

    /**
     * Chuyển hàng loạt hồ sơ approved + candidate đã nhập học thành đơn KTX.
     * POST /admin/dorm-reservations/batch-convert
     */
    public function batchConvert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_period_id' => ['required', 'integer', 'exists:registration_periods,id'],
        ]);

        $periodId = $data['registration_period_id'];
        $period   = RegistrationPeriod::findOrFail($periodId);

        $reservations = DormReservation::where('registration_period_id', $periodId)
            ->where('status', 'approved')
            ->with('candidate')
            ->get();

        $converted = 0;
        $skipped   = 0;
        $errors    = [];

        foreach ($reservations as $reservation) {
            $candidate = $reservation->candidate;

            if (!$candidate || $candidate->status !== 'enrolled' || !$candidate->student_id) {
                $skipped++;
                continue;
            }

            $studentId = $candidate->student_id;

            $existing = Registration::where('student_id', $studentId)
                ->where('registration_period_id', $periodId)
                ->whereNotIn('status', ['rejected'])
                ->exists();

            if ($existing) {
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($reservation, $period, $periodId, $studentId, &$converted) {
                    $registration = Registration::create([
                        'student_id'             => $studentId,
                        'registration_period_id' => $periodId,
                        'semester'               => $period->semester,
                        'school_year'            => $period->school_year,
                        'stay_from_date'         => $period->stay_start_date?->format('Y-m-d'),
                        'stay_to_date'           => $period->stay_end_date?->format('Y-m-d'),
                        'status'                 => 'approved',
                        'registration_type'      => 'new',
                        'approved_at'            => now(),
                        'note'                   => "Chuyển từ hồ sơ giữ chỗ #{$reservation->reservation_code}",
                        'commitment_confirm'      => true,
                        'avatar_url'             => $reservation->avatar_url,
                        'cccd_front_url'         => $reservation->cccd_front_url,
                        'cccd_back_url'          => $reservation->cccd_back_url,
                        'father_name'            => $reservation->father_name,
                        'father_birth_year'      => $reservation->father_birth_year,
                        'father_job'             => $reservation->father_job,
                        'father_phone'           => $reservation->father_phone,
                        'mother_name'            => $reservation->mother_name,
                        'mother_birth_year'      => $reservation->mother_birth_year,
                        'mother_job'             => $reservation->mother_job,
                        'mother_phone'           => $reservation->mother_phone,
                        'parent_address'         => $reservation->parent_address,
                        'top_priority_tier'      => $reservation->top_priority_tier,
                        'total_priority_score'   => $reservation->total_priority_score,
                    ]);

                    $this->copyPrioritiesToRegistration($reservation, $studentId, $registration->id);

                    $reservation->update([
                        'status'                    => 'converted',
                        'converted_registration_id' => $registration->id,
                    ]);

                    $student = Student::find($studentId);
                    if ($student) {
                        app(StudentNotificationService::class)->notifyStudent(
                            $student,
                            'Đơn đăng ký nội trú đã được duyệt',
                            'Đơn đăng ký nội trú KTX của bạn đã được duyệt. Vui lòng theo dõi thông báo để biết kết quả phân phòng.',
                            'registration_approved',
                            $registration->id,
                        );
                    }

                    $converted++;
                });
            } catch (\Throwable $e) {
                $errors[] = [
                    'reservation_id'   => $reservation->id,
                    'reservation_code' => $reservation->reservation_code,
                    'error'            => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message'   => "Đã chuyển đổi {$converted} hồ sơ.",
            'converted' => $converted,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ]);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function notifyCandidate(?AdmissionCandidate $candidate, string $title, string $content): void
    {
        if (!$candidate) {
            return;
        }

        app(StudentNotificationService::class)->notifyEmailOnly($candidate->email, $candidate->full_name, $title, $content);
    }

    private function generateReservationCode(): string
    {
        do {
            $code = 'DORM-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (DormReservation::where('reservation_code', $code)->exists());

        return $code;
    }

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reservation_code' => ['required', 'string'],
            'type'             => ['required', \Illuminate\Validation\Rule::in(['avatar', 'cccd_front', 'cccd_back'])],
            'file'             => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $reservation = DormReservation::where('id', $id)
            ->where('reservation_code', $request->input('reservation_code'))
            ->firstOrFail();

        $path = $request->file('file')->store('reservation-documents', 'public');
        $url  = '/api/storage/' . $path;

        $column = match ($request->input('type')) {
            'avatar'     => 'avatar_url',
            'cccd_front' => 'cccd_front_url',
            'cccd_back'  => 'cccd_back_url',
        };

        $reservation->update([$column => $url]);

        return response()->json(['message' => 'Đã tải lên thành công.', 'url' => $url]);
    }

    private function copyPrioritiesToRegistration(DormReservation $reservation, int $studentId, int $registrationId): void
    {
        $reservation->load('reservationPriorities.evidences');
        foreach ($reservation->reservationPriorities as $rp) {
            $sp = StudentPriority::create([
                'student_id'           => $studentId,
                'registration_id'      => $registrationId,
                'priority_criteria_id' => $rp->priority_criteria_id,
                'status'               => $rp->status,
            ]);
            foreach ($rp->evidences as $ev) {
                StudentPriorityEvidence::create([
                    'student_priority_id' => $sp->id,
                    'file_url'            => $ev->file_url,
                ]);
            }
        }
    }
}
