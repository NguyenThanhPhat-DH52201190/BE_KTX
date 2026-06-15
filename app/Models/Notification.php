<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notifications';

    // Table only has created_at (no updated_at).
    const UPDATED_AT = null;

    protected $fillable = [
        'student_id',
        'title',
        'content',
        'type',
        'target_type',
        'send_email',
    ];

    protected $casts = [
        'send_email' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
