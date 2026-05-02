<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
     protected $table = 'students';

    protected $fillable = [
        'student_code',
        'avatar',
        'full_name',
        'gender',
        'class_name',
        'faculty',
        'phone',
        'email',
        'cccd',
        'permanent_address',
        'password',
        'parent_name',
        'parent_phone',
        'parent_relationship',
        'status',
    ];

    public $timestamps = false; // vì bảng bạn KHÔNG có created_at
}
