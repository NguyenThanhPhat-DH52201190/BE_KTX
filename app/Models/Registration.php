<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Occupancy;

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
        'stay_from_date',
        'stay_to_date',
        'cccd_front_url',
        'cccd_back_url',
        'commitment_confirm',
        'status',
        'registration_type',
        'auto_decision',
        'note',
        'rejection_reason',
        'approved_at',
        'registration_period_id',
        'top_priority_tier',
        'total_priority_score',
    ];

    protected $casts = [
        'top_priority_tier' => 'integer',
        'total_priority_score' => 'integer',
    ];


    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function occupancy(): HasOne
    {
        return $this->hasOne(Occupancy::class);
    }
}