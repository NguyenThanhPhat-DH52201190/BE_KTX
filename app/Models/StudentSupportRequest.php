<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSupportRequest extends Model
{
    protected $table = 'student_support_requests';

    protected $fillable = [
        'student_id',
        'title',
        'content',
        'attachment_url',
        'status',
        'admin_note',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
