<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupancyExtension extends Model
{
    protected $table = 'occupancy_extensions';

    protected $fillable = [
        'occupancy_id',
        'student_id',
        'occupancy_period_id',
        'reason',
        'status',
        'admin_note',
        'requested_at',
        'approved_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function occupancyPeriod(): BelongsTo
    {
        return $this->belongsTo(OccupancyPeriod::class);
    }
}
