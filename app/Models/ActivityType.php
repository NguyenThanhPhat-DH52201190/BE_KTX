<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityType extends Model
{
    protected $table = 'activity_types';

    protected $fillable = [
        'name',
        'level',
        'description',
        'category',
        'points',
    ];

    protected $casts = [
        'points' => 'integer',
    ];
}
