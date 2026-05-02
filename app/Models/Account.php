<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Account extends Model
{
    use HasApiTokens;
    protected $table = 'accounts';

    protected $fillable = [
        'student_id',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password'
    ];

    public $timestamps = false;
}
