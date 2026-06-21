<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSupportRequest extends Model
{
    protected $table = 'student_support_requests';

    protected $fillable = [
        'student_id',
        'request_type',
        'title',
        'content',
        'attachment_url',
        'status',
        'admin_note',
        'target_room_id',
        'target_bed_id',
        'target_student_id',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function targetRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'target_room_id');
    }

    public function targetBed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'target_bed_id');
    }

    public function targetStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'target_student_id');
    }
}
