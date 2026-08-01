<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class Registration extends Model
{
    protected $table = 'registrations';

    protected $fillable = [
        'student_id',
        'avatar_url',
        'semester',
        'school_year',
        'father_name',
        'father_birth_year',
        'father_job',
        'father_phone',
        'mother_name',
        'mother_birth_year',
        'mother_job',
        'mother_phone',
        'parent_address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'stay_from_date',
        'stay_to_date',
        'cccd_front_url',
        'cccd_back_url',
        'commitment_confirm',
        'status',
        'registration_type',
        'auto_decision',
        'auto_decision_reason',
        'decision_source',
        'manual_decision',
        'manual_decision_reason',
        'note',
        'rejection_reason',
        'approved_at',
        'registration_period_id',
        'top_priority_tier',
        'total_priority_score',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
        'source_dorm_reservation_id',
    ];

    protected $casts = [
        'top_priority_tier' => 'integer',
        'total_priority_score' => 'integer',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];


    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RegistrationPeriod::class, 'registration_period_id');
    }

    public function studentPriorities(): HasMany
    {
        return $this->hasMany(StudentPriority::class, 'registration_id');
    }

    /** Có ít nhất 1 tiêu chí ưu tiên bị admin từ chối minh chứng — hồ sơ không được tiếp
     *  tục tham gia ranking/duyệt/promotion/convert cho tới khi xử lý lại (xem báo cáo
     *  sửa lỗi "minh chứng rejected vẫn được xếp hạng"). */
    public function hasRejectedPriorityEvidence(): bool
    {
        return $this->studentPriorities()->where('status', 'rejected')->exists();
    }

    /** Giữ chỗ nguồn (DormReservation) đã sinh ra Registration này qua conversion — chỉ set
     *  khi Registration được tạo từ luồng tân sinh viên, null với luồng tự đăng ký thường. */
    public function sourceDormReservation(): BelongsTo
    {
        return $this->belongsTo(DormReservation::class, 'source_dorm_reservation_id');
    }

    public function occupancy(): HasOne
    {
        // Gia hạn lưu trú tạo occupancy MỚI nhưng giữ cùng registration_id (xem
        // OccupancyExtensionController::store()/approve()) — 1 registration có thể có nhiều
        // occupancy theo thời gian. latestOfMany() đảm bảo luôn lấy đúng occupancy mới nhất
        // (id lớn nhất), không phải bản ghi đầu tiên theo thứ tự mặc định của DB.
        return $this->hasOne(Occupancy::class)->latestOfMany();
    }

    public function bedSelectionDeadline(): ?Carbon
    {
        $days = $this->period?->bed_selection_days;

        if (! $days || ! $this->approved_at) {
            return null;
        }

        return Carbon::parse($this->approved_at)
            ->addDays((int) $days)
            ->setTime(17, 0, 0);
    }
}
