<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public $timestamps = false;

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class, 'student_id');
    }
}
