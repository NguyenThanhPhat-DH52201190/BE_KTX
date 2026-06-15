<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Waitlist extends Model
{
    protected $table = 'waitlist';

    protected $fillable = [
        'registration_id',
        'student_id',
        'gender',
        'priority_tier',
        'priority_score',
        'queue_position',
        'source',
        'registration_period_id',
        'status',
        'notified_at',
    ];

    protected $casts = [
        'priority_tier' => 'integer',
        'priority_score' => 'integer',
        'queue_position' => 'integer',
        'notified_at' => 'datetime',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function registrationPeriod(): BelongsTo
    {
        return $this->belongsTo(RegistrationPeriod::class);
    }
}
