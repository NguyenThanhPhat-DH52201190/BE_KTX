<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'title',
        'content',
        'type',
        'is_read',
        'read_at',
        'created_at',
    ];

    protected $casts = [
        'is_read'    => 'boolean',
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
    ];
}
