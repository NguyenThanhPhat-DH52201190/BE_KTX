<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DormReservation extends Model
{
    protected $table = 'dorm_reservations';

    protected $fillable = [
        'admission_candidate_id',
        'registration_period_id',
        'reservation_code',
        'student_code',
        'status',
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
        'cancellation_reason',
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

    public function reservationPriorities(): HasMany
    {
        return $this->hasMany(ReservationPriority::class, 'dorm_reservation_id');
    }
}
