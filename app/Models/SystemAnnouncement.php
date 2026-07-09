<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemAnnouncement extends Model
{
    protected $fillable = [
        'title',
        'content',
        'type',
        'priority',
        'target_type',
        'target_value',
        'send_web',
        'send_email',
        'attachment_path',
        'scheduled_at',
        'sent_at',
        'status',
        'updated_by',
    ];

    protected $casts = [
        'send_web'     => 'boolean',
        'send_email'   => 'boolean',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(SystemAnnouncementRecipient::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'updated_by');
    }
}
