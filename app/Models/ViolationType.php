<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ViolationType extends Model
{
    protected $table = 'violation_types';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'level',
        'description',
    ];
}
