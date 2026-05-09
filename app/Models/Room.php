<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    protected $table = 'rooms';

    public $timestamps = false;

    protected $fillable = [
        'building_code',
        'room_number',
        'capacity',
        'price_per_quarter',
        'status',
    ];

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class, 'room_id');
    }
}
