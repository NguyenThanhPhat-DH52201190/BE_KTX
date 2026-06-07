<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Violation extends Model
{
    protected $table = 'violations';

    public $timestamps = false;

    protected $fillable = [
        'occupancy_id',
        'type_id',
        'violation_date',
        'note',
        'status',
        'action_taken',
    ];

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class, 'occupancy_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ViolationType::class, 'type_id');
    }
}
