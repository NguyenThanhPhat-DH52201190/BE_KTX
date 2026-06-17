<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPriorityEvidence extends Model
{
    protected $table = 'student_priority_evidence';

    protected $fillable = [
        'student_priority_id',
        'file_url',
    ];

    public function priority(): BelongsTo
    {
        return $this->belongsTo(StudentPriority::class, 'student_priority_id');
    }
}
