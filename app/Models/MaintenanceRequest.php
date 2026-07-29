<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRequest extends Model
{
    protected $table = 'maintenance_requests';

    protected $fillable = [
        'type',
        'room_id',
        'bed_id',
        'reason',
        'pending_assignments',
        'status',
        'started_at',
        'expected_end_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'started_at'          => 'datetime',
        'expected_end_at'     => 'datetime',
        'completed_at'        => 'datetime',
        'pending_assignments' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }
}
