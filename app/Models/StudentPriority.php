<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentPriority extends Model
{
    protected $table = 'student_priority';

    protected $fillable = [
        'student_id',
        'registration_id',
        'priority_criteria_id',
        'evidence_url',
        'status',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function criteria(): BelongsTo
    {
        return $this->belongsTo(PriorityCriteria::class, 'priority_criteria_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'verified_by');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(StudentPriorityEvidence::class, 'student_priority_id');
    }
}
