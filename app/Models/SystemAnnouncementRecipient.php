<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAnnouncementRecipient extends Model
{
    protected $fillable = [
        'system_announcement_id',
        'student_id',
        'notification_id',
        'email_status',
        'email_sent_at',
        'email_error',
    ];

    protected $casts = [
        'email_sent_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(SystemAnnouncement::class, 'system_announcement_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
