<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DormReservation extends Model
{
    protected $table = 'dorm_reservations';

    // Lý do cụ thể khi status chuyển 'expired' — do AutoCloseAdmissionPeriodsCommand ghi,
    // KHÔNG dùng rejection_reason/admin_note (2 cột đó do người nhập, mang ý nghĩa khác).
    // Việc 5 chỉ theo dõi đúng nhóm EXPIRATION_APPROVED_NOT_CONVERTED —
    // KHÔNG dùng tên "not_enrolled" vì candidate.status=enrolled không còn đồng nghĩa
    // reservation đã converted (submitted/waitlisted cũng tạo Student được từ Import nhập
    // học + DormReservationConversionService).
    public const EXPIRATION_PERIOD_CLOSED_WAITLISTED = 'period_closed_while_waitlisted';
    public const EXPIRATION_APPROVED_NOT_CONVERTED   = 'approved_not_converted';

    protected $fillable = [
        'admission_candidate_id',
        'registration_period_id',
        'reservation_code',
        'student_code',
        'status',
        'auto_decision',
        'auto_decision_reason',
        'priority_note',
        'father_name',
        'father_birth_year',
        'father_job',
        'father_phone',
        'mother_name',
        'mother_birth_year',
        'mother_job',
        'mother_phone',
        'parent_address',
        'commitment_confirm',
        'avatar_url',
        'cccd_front_url',
        'cccd_back_url',
        'rejection_reason',
        'expiration_reason',
        'admin_note',
        'submitted_at',
        'approved_at',
        'expires_at',
        'converted_registration_id',
        'top_priority_tier',
        'total_priority_score',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(AdmissionCandidate::class, 'admission_candidate_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RegistrationPeriod::class, 'registration_period_id');
    }

    public function convertedRegistration(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'converted_registration_id');
    }

    /** Registration đã được DormReservationConversionService tạo ra từ reservation này
     *  (source_dorm_reservation_id) — khác convertedRegistration() (chỉ gán khi admin xác
     *  nhận chính thức). Dùng để biết reservation đã "vào chung bàn xếp hạng" với đăng ký
     *  thường hay chưa (xem RegistrationPeriodController::process()). */
    public function convertedIntoRegistration(): HasOne
    {
        return $this->hasOne(Registration::class, 'source_dorm_reservation_id');
    }

    public function reservationPriorities(): HasMany
    {
        return $this->hasMany(ReservationPriority::class, 'dorm_reservation_id');
    }

    /** Có ít nhất 1 tiêu chí ưu tiên bị admin từ chối minh chứng — hồ sơ không được tiếp
     *  tục tham gia ranking/duyệt/promotion/convert cho tới khi xử lý lại (xem báo cáo
     *  sửa lỗi "minh chứng rejected vẫn được xếp hạng"). */
    public function hasRejectedPriorityEvidence(): bool
    {
        return $this->reservationPriorities()->where('status', 'rejected')->exists();
    }
}
