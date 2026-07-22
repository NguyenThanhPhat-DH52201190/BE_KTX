<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Building;
use App\Models\Room;

class Floor extends Model
{
    protected $table = 'floors';

    public $timestamps = false;

    protected $fillable = [
        'building_code',
        'floor_number',
        'gender',
        'status',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_code', 'building_code');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'floor_id');
    }
}