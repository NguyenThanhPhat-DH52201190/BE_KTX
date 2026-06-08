<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectricityRecord extends Model
{
    protected $table = 'electricity_records';

    protected $fillable = [
        'room_id',
        'month_year',
        'old_index',
        'new_index',
        'usage_kwh',
        'unit_price',
        'total_amount',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
