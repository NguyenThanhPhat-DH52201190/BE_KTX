<?php

namespace App\Services;

use App\Models\AdmissionCandidate;
use App\Models\DormReservation;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\Student;
use App\Models\StudentPriority;
use App\Models\StudentPriorityEvidence;
use Illuminate\Support\Facades\DB;

/**
 * Nguồn DUY NHẤT để chuyển 1 dorm_reservation đã approved + candidate đã enrolled thành
 * Registration chính thức — dùng chung cho AdmissionCandidateController::bulkEnroll()
 * (candidate vừa enrolled, reservation đã approved sẵn), DormReservationController::approve()/
 * rankReservations() (reservation vừa approved, candidate đã enrolled từ trước), và Việc 6
 * (waitlisted được đôn lên approved). KHÔNG viết lại logic tạo Registration ở nơi khác.
 */
class DormReservationConversionService
{
    /**
     * @param bool $notify false khi caller (vd. DormWaitlistPromotionService) tự gửi 1
     *                      thông báo tổng hợp riêng cho người dùng — tránh gửi trùng 2
     *                      thông báo cho cùng 1 hành động "được đôn + tự tạo đơn".
     * @return Registration|null Registration vừa tạo, hoặc null nếu không đủ điều kiện
     *                           (không phải approved, candidate chưa enrolled, đã có
     *                           Registration hợp lệ trong cùng đợt, hoặc đã converted).
     */
    public function convert(DormReservation $reservation, bool $notify = true): ?Registration
    {
        return DB::transaction(function () use ($reservation, $notify) {
            $reservation = DormReservation::where('id', $reservation->id)->lockForUpdate()->first();

            if (!$reservation || $reservation->status !== 'approved' || $reservation->converted_registration_id) {
                return null;
            }

            // Minh chứng ưu tiên bị từ chối — không được convert thành Registration (phòng
            // vệ thêm; bình thường reservation đã tự cascade sang 'rejected' ngay khi admin
            // từ chối minh chứng nên đã bị chặn ở check status phía trên rồi).
            if ($reservation->hasRejectedPriorityEvidence()) {
                return null;
            }

            // convert() không còn set converted_registration_id ngay (xem lý do ở khối tạo
            // Registration bên dưới) nên check converted_registration_id ở trên không đủ
            // chặn gọi trùng — phải check thêm bằng chính source_dorm_reservation_id.
            if (Registration::where('source_dorm_reservation_id', $reservation->id)->exists()) {
                return null;
            }

            $candidate = AdmissionCandidate::where('id', $reservation->admission_candidate_id)
                ->lockForUpdate()
                ->first();

            if (!$candidate || $candidate->status !== 'enrolled' || !$candidate->student_id) {
                return null;
            }

            $studentId = $candidate->student_id;

            // 'cancelled' loại khỏi check trùng — Registration đã hủy tự phục vụ không
            // được tính là "đang có đơn hợp lệ" chặn tạo Registration mới trong cùng đợt.
            $existingRegistration = Registration::where('student_id', $studentId)
                ->where('registration_period_id', $reservation->registration_period_id)
                ->whereNotIn('status', ['rejected', 'cancelled'])
                ->lockForUpdate()
                ->exists();

            if ($existingRegistration) {
                return null;
            }

            $period  = RegistrationPeriod::find($reservation->registration_period_id);
            $student = Student::find($studentId);

            // Nguồn cha/mẹ ưu tiên: dorm_reservation nếu có sẵn (hồ sơ tạo trước khi bỏ
            // thu thông tin này lúc giữ chỗ), nếu không thì lấy từ student (Excel nhập
            // học). Liên hệ khẩn cấp luôn lấy từ student vì dorm_reservations chưa bao
            // giờ có 3 cột này.
            $familySource = $reservation->father_name ? $reservation : $student;

            $registration = Registration::create([
                'student_id'             => $studentId,
                'registration_period_id' => $reservation->registration_period_id,
                'registration_type'      => 'new',
                'semester'               => $period?->semester,
                'school_year'            => $period?->school_year,
                'stay_from_date'         => $period?->stay_start_date?->format('Y-m-d'),
                'stay_to_date'           => $period?->stay_end_date?->format('Y-m-d'),
                // Registration nguồn giữ chỗ để 'submitted' + auto_decision=null, GIỐNG HỆT
                // đăng ký thường mới nộp — PriorityRankingService::rankPeriodUnified() giờ xếp
                // hạng CHUNG cả 2 nguồn trong 1 bảng công bằng (báo cáo 24/07), nên không được
                // set sẵn auto_decision='approve' ở đây nữa (trước đây làm vậy vì hồ sơ giữ chỗ
                // từng được đảm bảo duyệt, tách riêng khỏi xếp hạng chung — giờ không còn đúng,
                // set sẵn sẽ hiện nhầm badge "Duyệt" trong khi đơn thực chất CHƯA được xếp hạng
                // lần nào ở cấp đợt). Admin vẫn phải bấm "Xếp hạng" cho đợt rồi mới "Xác nhận"
                // (RegistrationController::confirmSingle()/confirmBatch()) để chính thức
                // chuyển approved + convert DormReservation → converted.
                'status'                     => 'submitted',
                'auto_decision'              => null,
                'auto_decision_reason'       => null,
                'approved_at'                => null,
                'source_dorm_reservation_id' => $reservation->id,
                'note'                   => "Chuyển từ hồ sơ giữ chỗ #{$reservation->reservation_code}",
                'commitment_confirm'     => $reservation->commitment_confirm ?? true,
                'avatar_url'             => $reservation->avatar_url,
                'cccd_front_url'         => $reservation->cccd_front_url,
                'cccd_back_url'          => $reservation->cccd_back_url,
                'father_name'            => $familySource?->father_name,
                'father_birth_year'      => $familySource?->father_birth_year,
                'father_job'             => $familySource?->father_job,
                'father_phone'           => $familySource?->father_phone,
                'mother_name'            => $familySource?->mother_name,
                'mother_birth_year'      => $familySource?->mother_birth_year,
                'mother_job'             => $familySource?->mother_job,
                'mother_phone'           => $familySource?->mother_phone,
                'parent_address'         => $familySource?->parent_address,
                'emergency_contact_name'         => $student?->emergency_contact_name,
                'emergency_contact_phone'        => $student?->emergency_contact_phone,
                'emergency_contact_relationship' => $student?->emergency_contact_relationship,
                'top_priority_tier'      => $reservation->top_priority_tier,
                'total_priority_score'   => $reservation->total_priority_score,
            ]);

            $this->copyPriorities($reservation, $studentId, $registration->id);

            // KHÔNG chuyển reservation sang 'converted' và KHÔNG set converted_registration_id
            // ở đây — DormCapacityService dùng đúng 2 tín hiệu này để loại reservation khỏi
            // approved_dorm_reservations (xem summarizeForRegistrationPeriod()). Set sớm ở
            // đây sẽ khiến suất "biến mất" khỏi mọi bộ đếm (Registration vẫn 'submitted' nên
            // approved_unassigned_registrations cũng chưa đếm) trong lúc chờ admin xác nhận.
            // Việc chuyển 'converted' + set converted_registration_id chỉ xảy ra trong
            // RegistrationController::confirmSingle()/confirmBatch()/approve() khi admin xác
            // nhận chính thức.
            $reservation->update([
                'student_code' => $student?->student_code,
            ]);

            if ($student && $notify) {
                app(StudentNotificationService::class)->notifyStudent(
                    $student,
                    'Đơn đăng ký nội trú đã được tạo',
                    'Đơn đăng ký nội trú của bạn đã được tạo và đang chờ Ban quản lý xác nhận.',
                    'registration_pending_confirmation',
                    $registration->id,
                    queue: true,
                );
            }

            return $registration;
        });
    }

    private function copyPriorities(DormReservation $reservation, int $studentId, int $registrationId): void
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
