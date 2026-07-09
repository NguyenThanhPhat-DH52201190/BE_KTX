<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class RegistrationPeriod extends Model
{
    protected $table = 'registration_periods';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'stay_start_date',
        'stay_end_date',
        'status',
        'channel',
        'school_year',
        'semester',
        'bed_selection_days',
        'processing_days',
        'initial_payment_due_days',
        'round_number',
        'allow_admission_candidates',
        'requires_student_code',
    ];

    protected $casts = [
        'start_date'      => 'date:Y-m-d',
        'end_date'        => 'date:Y-m-d',
        'stay_start_date' => 'date:Y-m-d',
        'stay_end_date'   => 'date:Y-m-d',
        'bed_selection_days'         => 'integer',
        'processing_days'            => 'integer',
        'initial_payment_due_days'   => 'integer',
        'round_number'               => 'integer',
        'allow_admission_candidates' => 'boolean',
        'requires_student_code'      => 'boolean',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function waitlist(): HasMany
    {
        return $this->hasMany(Waitlist::class);
    }

    public function dormReservations(): HasMany
    {
        return $this->hasMany(DormReservation::class);
    }

    /**
     * Ngày kết thúc lưu trú thật của lứa sinh viên hiện tại — nguồn duy nhất để xác định
     * occupancy nào đủ điều kiện gia hạn (KHÔNG dùng OccupancyPeriod::end_date, vì field đó
     * chỉ là hạn chót nhận đơn, không nhất thiết trùng ngày dọn ra thật).
     */
    public static function currentStayEndDate(): ?string
    {
        return static::orderByDesc('stay_end_date')->value('stay_end_date');
    }
}
