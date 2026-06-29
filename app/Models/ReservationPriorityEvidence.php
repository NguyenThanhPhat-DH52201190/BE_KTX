<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationPriorityEvidence extends Model
{
    protected $table = 'reservation_priority_evidences';

    protected $fillable = [
        'reservation_priority_id',
        'file_url',
        'original_name',
        'mime_type',
        'file_size',
    ];

    public function priority(): BelongsTo
    {
        return $this->belongsTo(ReservationPriority::class, 'reservation_priority_id');
    }
}
