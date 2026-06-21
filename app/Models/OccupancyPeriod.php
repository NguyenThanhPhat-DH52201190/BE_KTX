<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OccupancyPeriod extends Model
{
    protected $table = 'occupancy_periods';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'extension_until_date',
        'status',
        'description',
    ];

    protected $casts = [
        'start_date'           => 'date:Y-m-d',
        'end_date'             => 'date:Y-m-d',
        'extension_until_date' => 'date:Y-m-d',
    ];

    public function extensions(): HasMany
    {
        return $this->hasMany(OccupancyExtension::class);
    }
}
