<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Floor;

class Building extends Model
{
    protected $table = 'buildings';

    protected $primaryKey = 'building_code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'building_code',
        'name',
        'address',
        'total_floors',
        'gender_config',
        'status',
    ];

    protected $casts = [
        'gender_config' => 'array',
    ];

    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class, 'building_code', 'building_code');
    }
}